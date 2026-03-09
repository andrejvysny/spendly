<?php

/**
 * Delete sandbox accounts and re-import/sync from existing requisition.
 * Usage: docker compose run cli php scripts/gc_sandbox_resync.php <requisition_id> [user_id]
 */

require_once __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Account;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;

$requisitionId = $argv[1] ?? '3fc6dc3c-8ff3-4351-ab18-d3d8483b3cae';
$userId = $argv[2] ?? 2;

$user = User::find($userId);
$service = app(GoCardlessService::class);

echo "=== GoCardless Sandbox Re-Sync ===\n\n";

// Delete old sandbox accounts (IDs 23, 24)
$sandboxAccounts = Account::where('user_id', $userId)
    ->whereNotNull('gocardless_account_id')
    ->where('gocardless_account_id', 'like', '%-%-%-%-%')
    ->get();

echo "--- Cleaning up old sandbox accounts ---\n";
foreach ($sandboxAccounts as $acc) {
    $txCount = $acc->transactions()->count();
    echo "Deleting account {$acc->id} ({$acc->name}) with {$txCount} transactions...\n";
    $acc->transactions()->delete();
    $acc->delete();
    echo "  Done.\n";
}

echo "\n--- Re-import from requisition {$requisitionId} ---\n";
try {
    $requisition = $service->getRequisition($requisitionId, $user);
    $status = $requisition['status'] ?? 'unknown';
    echo "Requisition status: {$status}\n";
    $accountIds = $requisition['accounts'] ?? [];
    echo 'Accounts: '.count($accountIds)."\n";

    foreach ($accountIds as $gcId) {
        try {
            $account = $service->importAccount($gcId, $user);
            echo "✓ Imported: {$gcId} → local ID {$account->id}\n";
        } catch (\App\Exceptions\AccountAlreadyExistsException) {
            echo "⊘ Already exists: {$gcId}\n";
        } catch (\Throwable $e) {
            echo '✗ Import failed: '.$e->getMessage()."\n";
        }
    }
} catch (\Throwable $e) {
    echo '✗ Failed: '.$e->getMessage()."\n";
    exit(1);
}

echo "\n--- Sync transactions ---\n";
$localAccounts = Account::where('user_id', $userId)
    ->where('is_gocardless_synced', true)
    ->whereRaw("gocardless_account_id LIKE '%-%-%-%-%'")
    ->get();

foreach ($localAccounts as $account) {
    echo "\nSyncing account {$account->id} ({$account->name})...\n";
    try {
        $result = $service->syncAccountTransactions($account->id, $user, true, true);
        $stats = $result['stats'] ?? [];
        echo '✓ Created: '.($stats['created'] ?? 0)."\n";
        echo '  Updated: '.($stats['updated'] ?? 0)."\n";
        echo '  Skipped: '.($stats['skipped'] ?? 0)."\n";
        echo '  Errors:  '.($stats['errors'] ?? 0)."\n";
        echo '  Date range: '.($result['date_range']['date_from'] ?? '?').' → '.($result['date_range']['date_to'] ?? '?')."\n";
        echo '  Balance updated: '.($result['balance_updated'] ? 'yes' : 'no')."\n";
    } catch (\Throwable $e) {
        echo '✗ Sync failed: '.$e->getMessage()."\n";
    }
}

echo "\n--- Verification ---\n";
foreach ($localAccounts->fresh() as $account) {
    $account = Account::find($account->id);
    $txCount = $account->transactions()->count();
    echo "Account '{$account->name}' (ID:{$account->id}): balance={$account->balance} {$account->currency}, transactions={$txCount}\n";
}

$failures = \Illuminate\Support\Facades\DB::table('gocardless_sync_failures')
    ->where('user_id', $userId)
    ->whereIn('account_id', $localAccounts->pluck('id'))
    ->count();
echo "Sync failures: {$failures}\n";

echo "\n=== Done ===\n";
