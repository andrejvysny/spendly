<?php

/**
 * GoCardless Sandbox Step 2: Check requisition, import accounts, sync transactions.
 * Usage: docker compose run cli php scripts/gc_sandbox_step2.php <requisition_id> [user_id]
 */

require_once __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Services\GoCardless\GoCardlessService;

$requisitionId = $argv[1] ?? null;
$userId = $argv[2] ?? 2;

if (! $requisitionId) {
    echo "Usage: php scripts/gc_sandbox_step2.php <requisition_id> [user_id]\n";
    exit(1);
}

$user = User::find($userId);
if (! $user) {
    echo "ERROR: User {$userId} not found\n";
    exit(1);
}

$service = app(GoCardlessService::class);

echo "=== GoCardless Sandbox — Step 2 ===\n";
echo "Requisition: {$requisitionId}\n";
echo "User: {$user->name}\n\n";

// Step 1: Check requisition status
echo "--- Check Requisition Status ---\n";
try {
    $requisition = $service->getRequisition($requisitionId, $user);
    $status = $requisition['status'] ?? 'unknown';
    echo "Status: {$status}\n";

    if ($status !== 'LN') {
        echo "⚠ Requisition is not yet linked (status: {$status}).\n";
        echo "Visit the auth link in your browser first, then re-run this script.\n";
        if ($status === 'CR') {
            echo 'Link: '.($requisition['link'] ?? 'N/A')."\n";
        }
        exit(0);
    }

    $accountIds = $requisition['accounts'] ?? [];
    echo '✓ Linked! Found '.count($accountIds)." account(s)\n";
    foreach ($accountIds as $i => $id) {
        echo "  [{$i}] {$id}\n";
    }
} catch (\Throwable $e) {
    echo '✗ Failed: '.$e->getMessage()."\n";
    exit(1);
}

// Step 2: Get account details
echo "\n--- Account Details ---\n";
foreach ($accountIds as $accountId) {
    try {
        $details = $service->getEnrichedAccountsForRequisition([$accountId], $user);
        $detail = $details[0] ?? [];
        echo "Account: {$accountId}\n";
        echo '  Name: '.($detail['name'] ?? 'N/A')."\n";
        echo '  IBAN: '.($detail['iban'] ?? 'N/A')."\n";
        echo '  Currency: '.($detail['currency'] ?? 'N/A')."\n";
        echo '  Owner: '.($detail['owner_name'] ?? 'N/A')."\n";
        echo '  Status: '.($detail['status'] ?? 'N/A')."\n";
    } catch (\Throwable $e) {
        echo '  ✗ Failed to get details: '.$e->getMessage()."\n";
    }
}

// Step 3: Import accounts
echo "\n--- Import Accounts ---\n";
foreach ($accountIds as $accountId) {
    try {
        $account = $service->importAccount($accountId, $user);
        echo "✓ Imported: {$accountId} → local ID {$account->id} ({$account->name})\n";
    } catch (\App\Exceptions\AccountAlreadyExistsException) {
        echo "⊘ Already imported: {$accountId}\n";
    } catch (\Throwable $e) {
        echo "✗ Import failed for {$accountId}: ".$e->getMessage()."\n";
    }
}

// Step 4: Sync transactions
echo "\n--- Sync Transactions ---\n";
$localAccounts = \App\Models\Account::where('user_id', $user->id)
    ->where('is_gocardless_synced', true)
    ->get();

foreach ($localAccounts as $account) {
    echo "Syncing account {$account->id} ({$account->name})...\n";
    try {
        $result = $service->syncAccountTransactions($account->id, $user, true, true);
        $stats = $result['stats'] ?? [];
        echo "✓ Sync complete:\n";
        echo '  Created: '.($stats['created'] ?? 0)."\n";
        echo '  Updated: '.($stats['updated'] ?? 0)."\n";
        echo '  Skipped: '.($stats['skipped'] ?? 0)."\n";
        echo '  Date range: '.($result['date_range']['date_from'] ?? '?').' → '.($result['date_range']['date_to'] ?? '?')."\n";
        echo '  Balance updated: '.($result['balance_updated'] ? 'yes' : 'no')."\n";
    } catch (\Throwable $e) {
        echo '✗ Sync failed: '.$e->getMessage()."\n";
    }
}

// Step 5: Verify data
echo "\n--- Verification ---\n";
$txCount = \App\Models\Transaction::where('user_id', $user->id)->count();
echo "Total transactions for user: {$txCount}\n";

foreach ($localAccounts as $account) {
    $account->refresh();
    $accTx = $account->transactions()->count();
    echo "Account '{$account->name}' (ID:{$account->id}): balance={$account->balance} {$account->currency}, transactions={$accTx}\n";
}

// Check sync failures
$failures = \Illuminate\Support\Facades\DB::table('gocardless_sync_failures')
    ->where('user_id', $user->id)
    ->count();
echo "Sync failures: {$failures}\n";

echo "\n=== Sandbox Test Complete ===\n";
