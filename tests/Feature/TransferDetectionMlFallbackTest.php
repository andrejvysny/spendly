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
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransferDetectionMlFallbackTest extends TestCase
{
    private function createMlService(bool $enabled = true): MlService
    {
        config([
            'services.ml.enabled' => $enabled,
            'services.ml.url' => 'http://ml-test:8001',
            'services.ml.timeout' => 5,
        ]);

        return new MlService;
    }

    private function createService(?MlService $mlService = null): TransferDetectionService
    {
        return new TransferDetectionService(
            $this->app->make(AccountRepositoryInterface::class),
            $this->app->make(TransactionRepositoryInterface::class),
            $mlService
        );
    }

    public function test_ml_fallback_returns_zero_when_ml_disabled(): void
    {
        $service = $this->createService($this->createMlService(enabled: false));
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

        $service = $this->createService($this->createMlService(enabled: true));
        $user = User::factory()->create();

        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['rule_matched']);
        $this->assertSame(0, $result['ml_matched']);
    }

    public function test_ml_fallback_returns_zero_when_no_ml_service(): void
    {
        $service = $this->createService();
        $user = User::factory()->create();

        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['rule_matched']);
        $this->assertSame(0, $result['ml_matched']);
    }

    public function test_ml_fallback_marks_single_leg_prediction_for_manual_review_only(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'iban' => 'SK1234567890',
        ]);
        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'type' => Transaction::TYPE_CARD_PAYMENT,
            'amount' => -200.00,
            'currency' => 'EUR',
            'booked_date' => '2026-02-10',
            'processed_date' => '2026-02-10',
            'transfer_pair_transaction_id' => null,
            'needs_manual_review' => false,
            'review_reason' => null,
        ]);

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

        $service = $this->createService($this->createMlService(enabled: true));
        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['ml_matched']);

        $transaction->refresh();
        $this->assertSame(Transaction::TYPE_CARD_PAYMENT, $transaction->type);
        $this->assertTrue($transaction->needs_manual_review);
        $this->assertSame(TransferDetectionService::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED, $transaction->review_reason);
        $this->assertNull($transaction->transfer_pair_transaction_id);
    }

    public function test_ml_fallback_skips_low_confidence_predictions(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'iban' => 'SK1234567890',
        ]);
        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'type' => Transaction::TYPE_CARD_PAYMENT,
            'amount' => -50.00,
            'currency' => 'EUR',
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

        $service = $this->createService($this->createMlService(enabled: true));
        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['ml_matched']);

        $transaction->refresh();
        $this->assertSame(Transaction::TYPE_CARD_PAYMENT, $transaction->type);
        $this->assertFalse($transaction->needs_manual_review);
        $this->assertNull($transaction->review_reason);
    }

    public function test_ml_fallback_rejects_pair_outside_user_scope(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $userAccount = Account::factory()->create([
            'user_id' => $user->id,
            'iban' => 'SK1111000000001111111111',
        ]);
        $otherAccount = Account::factory()->create([
            'user_id' => $otherUser->id,
            'iban' => 'SK2222000000002222222222',
        ]);

        $transaction = Transaction::factory()->create([
            'account_id' => $userAccount->id,
            'type' => Transaction::TYPE_PAYMENT,
            'amount' => -125.00,
            'currency' => 'EUR',
            'booked_date' => '2026-02-11',
            'processed_date' => '2026-02-11',
            'target_iban' => $otherAccount->iban,
        ]);
        $otherTransaction = Transaction::factory()->create([
            'account_id' => $otherAccount->id,
            'type' => Transaction::TYPE_DEPOSIT,
            'amount' => 125.00,
            'currency' => 'EUR',
            'booked_date' => '2026-02-11',
            'processed_date' => '2026-02-11',
            'source_iban' => $userAccount->iban,
        ]);

        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/detect-transfers' => Http::response([
                [
                    'transaction_id' => $transaction->id,
                    'is_transfer' => true,
                    'confidence' => 0.91,
                    'method' => 'ml_classifier',
                    'suggested_pair_id' => $otherTransaction->id,
                ],
            ], 200),
        ]);

        $service = $this->createService($this->createMlService(enabled: true));
        $result = $service->detectTransfersWithMlFallback($user->id);

        $this->assertSame(0, $result['ml_matched']);

        $transaction->refresh();
        $otherTransaction->refresh();
        $this->assertSame(Transaction::TYPE_PAYMENT, $transaction->type);
        $this->assertTrue($transaction->needs_manual_review);
        $this->assertSame(TransferDetectionService::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED, $transaction->review_reason);
        $this->assertSame(Transaction::TYPE_DEPOSIT, $otherTransaction->type);
        $this->assertNull($otherTransaction->transfer_pair_transaction_id);
    }

    public function test_ml_fallback_respects_requested_date_range_and_forwards_it_to_ml_service(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'iban' => 'SK1234567890',
        ]);
        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'type' => Transaction::TYPE_PAYMENT,
            'amount' => -60.00,
            'currency' => 'EUR',
            'booked_date' => '2026-02-20',
            'processed_date' => '2026-02-20',
        ]);

        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/detect-transfers' => Http::response([
                [
                    'transaction_id' => $transaction->id,
                    'is_transfer' => true,
                    'confidence' => 0.88,
                    'method' => 'ml_classifier',
                    'suggested_pair_id' => null,
                ],
            ], 200),
        ]);

        $from = Carbon::parse('2026-02-01');
        $to = Carbon::parse('2026-02-15');

        $service = $this->createService($this->createMlService(enabled: true));
        $result = $service->detectTransfersWithMlFallback($user->id, $from, $to);

        $this->assertSame(0, $result['rule_matched']);
        $this->assertSame(0, $result['ml_matched']);

        $transaction->refresh();
        $this->assertFalse($transaction->needs_manual_review);
        $this->assertNull($transaction->review_reason);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'http://ml-test:8001/api/v1/detect-transfers') {
                return false;
            }

            $payload = $request->data();

            return $payload['from'] === '2026-02-01'
                && $payload['to'] === '2026-02-15'
                && $payload['limit'] === 500;
        });
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
            ->assertExitCode(0);
    }
}
