<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MlService;
use App\Services\TransferDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TransferDetectionMlFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function createMlService(bool $enabled = true): MlService
    {
        config([
            'services.ml.enabled' => $enabled,
            'services.ml.url' => 'http://ml-test:8001',
            'services.ml.timeout' => 5,
        ]);

        return new MlService;
    }

    public function test_ml_fallback_returns_zero_when_ml_disabled(): void
    {
        $accountRepo = $this->app->make(AccountRepositoryInterface::class);
        $transactionRepo = $this->app->make(TransactionRepositoryInterface::class);
        $mlService = $this->createMlService(enabled: false);

        $service = new TransferDetectionService($accountRepo, $transactionRepo, $mlService);

        $user = User::factory()->create();
        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['rule_matched']);
        $this->assertSame(0, $result['ml_matched']);
    }

    public function test_ml_fallback_returns_zero_when_ml_unavailable(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response('error', 500),
        ]);

        $accountRepo = $this->app->make(AccountRepositoryInterface::class);
        $transactionRepo = $this->app->make(TransactionRepositoryInterface::class);
        $mlService = $this->createMlService(enabled: true);

        $service = new TransferDetectionService($accountRepo, $transactionRepo, $mlService);

        $user = User::factory()->create();
        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['rule_matched']);
        $this->assertSame(0, $result['ml_matched']);
    }

    public function test_ml_fallback_returns_zero_when_no_ml_service(): void
    {
        $accountRepo = $this->app->make(AccountRepositoryInterface::class);
        $transactionRepo = $this->app->make(TransactionRepositoryInterface::class);

        $service = new TransferDetectionService($accountRepo, $transactionRepo);

        $user = User::factory()->create();
        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['rule_matched']);
        $this->assertSame(0, $result['ml_matched']);
    }

    public function test_ml_fallback_marks_single_leg_transfer(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/detect-transfers' => Http::response([
                [
                    'transaction_id' => null, // will be replaced
                    'is_transfer' => true,
                    'confidence' => 0.85,
                    'method' => 'regex',
                    'suggested_pair_id' => null,
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['iban' => 'SK1234567890']);
        $transaction = Transaction::factory()->for($account)->create([
            'type' => 'CARD_PAYMENT',
            'amount' => -200.00,
            'transfer_pair_transaction_id' => null,
        ]);

        // Update the fake response with the real transaction ID
        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/detect-transfers' => Http::response([
                [
                    'transaction_id' => $transaction->id,
                    'is_transfer' => true,
                    'confidence' => 0.85,
                    'method' => 'regex',
                    'suggested_pair_id' => null,
                ],
            ], 200),
        ]);

        $accountRepo = $this->app->make(AccountRepositoryInterface::class);
        $transactionRepo = $this->app->make(TransactionRepositoryInterface::class);
        $mlService = $this->createMlService(enabled: true);

        $service = new TransferDetectionService($accountRepo, $transactionRepo, $mlService);
        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(1, $result['ml_matched']);

        $transaction->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $transaction->type);
    }

    public function test_ml_fallback_skips_low_confidence(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['iban' => 'SK1234567890']);
        $transaction = Transaction::factory()->for($account)->create([
            'type' => 'CARD_PAYMENT',
            'amount' => -50.00,
            'transfer_pair_transaction_id' => null,
        ]);

        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/detect-transfers' => Http::response([
                [
                    'transaction_id' => $transaction->id,
                    'is_transfer' => true,
                    'confidence' => 0.40,
                    'method' => 'ml_classifier',
                    'suggested_pair_id' => null,
                ],
            ], 200),
        ]);

        $accountRepo = $this->app->make(AccountRepositoryInterface::class);
        $transactionRepo = $this->app->make(TransactionRepositoryInterface::class);
        $mlService = $this->createMlService(enabled: true);

        $service = new TransferDetectionService($accountRepo, $transactionRepo, $mlService);
        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['ml_matched']);

        $transaction->refresh();
        $this->assertSame('CARD_PAYMENT', $transaction->type);
    }

    public function test_transfers_detect_command_with_ml_flag(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/detect-transfers' => Http::response([], 200),
        ]);

        config([
            'services.ml.enabled' => true,
            'services.ml.url' => 'http://ml-test:8001',
        ]);

        $user = User::factory()->create();

        $this->artisan('transfers:detect', ['--user' => $user->id, '--ml' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('rule-matched')
            ->expectsOutputToContain('ML-matched');
    }
}
