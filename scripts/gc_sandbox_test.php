<?php

/**
 * GoCardless Sandbox integration test script.
 * Usage: docker compose run cli php scripts/gc_sandbox_test.php [user_id]
 */

require_once __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$userId = $argv[1] ?? 2;
$user = User::find($userId);

if (! $user) {
    echo "ERROR: User {$userId} not found\n";
    exit(1);
}

echo "=== GoCardless Sandbox Test ===\n";
echo "User: {$user->name} ({$user->email})\n";
echo 'use_mock: '.var_export(config('services.gocardless.use_mock'), true)."\n\n";

// Check credentials
if (empty($user->gocardless_secret_id) || empty($user->gocardless_secret_key)) {
    echo "ERROR: No GoCardless credentials on user {$userId}.\n";
    echo "Go to http://localhost/settings/bank_data and enter your credentials first.\n";
    exit(1);
}
echo '✓ Credentials found (secret_id: '.substr($user->gocardless_secret_id, 0, 8)."...)\n";

// Step 1: Test token acquisition
echo "\n--- Step 1: Token Acquisition ---\n";
try {
    $tokenManager = app(\App\Services\GoCardless\TokenManager::class, ['user' => $user]);
    $token = $tokenManager->getAccessToken();
    echo '✓ Access token acquired ('.substr($token, 0, 20)."...)\n";
} catch (\Throwable $e) {
    echo '✗ Token acquisition failed: '.$e->getMessage()."\n";
    exit(1);
}

// Step 2: Test institutions endpoint — check which country has SANDBOXFINANCE
echo "\n--- Step 2: Find Sandbox Institution ---\n";
$baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

// Try XX first (test country), then GB
foreach (['XX', 'GB'] as $country) {
    $response = Http::withToken($token)->get("{$baseUrl}/institutions?country={$country}");
    if ($response->successful()) {
        $institutions = $response->json();
        $sandbox = array_filter($institutions, fn ($i) => str_contains($i['id'] ?? '', 'SANDBOX'));
        if (! empty($sandbox)) {
            $sandbox = array_values($sandbox)[0];
            echo "✓ Found sandbox under country '{$country}': {$sandbox['id']} ({$sandbox['name']})\n";
            break;
        }
        echo "  No sandbox institution under '{$country}' (found ".count($institutions)." institutions)\n";
    } else {
        echo "  Failed to fetch institutions for '{$country}': {$response->status()} {$response->body()}\n";
    }
}

if (empty($sandbox)) {
    // Try without country filter
    echo "  Trying without country filter...\n";
    $response = Http::withToken($token)->get("{$baseUrl}/institutions/SANDBOXFINANCE_SFIN0000/");
    if ($response->successful()) {
        echo '✓ Direct lookup worked: '.json_encode($response->json(), JSON_PRETTY_PRINT)."\n";
    } else {
        echo "✗ Cannot find SANDBOXFINANCE: {$response->status()}\n";
    }
}

// Step 3: Create End User Agreement
echo "\n--- Step 3: Create End User Agreement ---\n";
try {
    $agreementResponse = Http::withToken($token)->post("{$baseUrl}/agreements/enduser/", [
        'institution_id' => 'SANDBOXFINANCE_SFIN0000',
        'max_historical_days' => 90,
        'access_valid_for_days' => 90,
        'access_scope' => ['balances', 'details', 'transactions'],
    ]);

    if ($agreementResponse->successful()) {
        $agreement = $agreementResponse->json();
        echo "✓ Agreement created: {$agreement['id']}\n";
    } else {
        echo "✗ Agreement creation failed: {$agreementResponse->status()} {$agreementResponse->body()}\n";
        $agreement = null;
    }
} catch (\Throwable $e) {
    echo '✗ Agreement error: '.$e->getMessage()."\n";
    $agreement = null;
}

// Step 4: Create Requisition
echo "\n--- Step 4: Create Requisition ---\n";
$redirectUrl = 'http://localhost/api/bank-data/gocardless/requisition/callback';
try {
    $payload = [
        'institution_id' => 'SANDBOXFINANCE_SFIN0000',
        'redirect' => $redirectUrl,
        'user_language' => 'EN',
    ];
    if ($agreement) {
        $payload['agreement'] = $agreement['id'];
    }

    $reqResponse = Http::withToken($token)->post("{$baseUrl}/requisitions/", $payload);

    if ($reqResponse->successful()) {
        $requisition = $reqResponse->json();
        echo "✓ Requisition created: {$requisition['id']}\n";
        echo "  Status: {$requisition['status']}\n";
        echo "  Link: {$requisition['link']}\n";
        echo "\n=== NEXT STEP ===\n";
        echo "Visit this link in your browser to complete sandbox auth:\n";
        echo $requisition['link']."\n";
        echo "\nThen run:\n";
        echo "docker compose run cli php scripts/gc_sandbox_test.php {$userId} --check-requisition={$requisition['id']}\n";
    } else {
        echo "✗ Requisition creation failed: {$reqResponse->status()} {$reqResponse->body()}\n";
    }
} catch (\Throwable $e) {
    echo '✗ Requisition error: '.$e->getMessage()."\n";
}

echo "\n=== Test Complete ===\n";
