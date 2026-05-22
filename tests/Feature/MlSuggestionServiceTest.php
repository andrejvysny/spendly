<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MlSuggestionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MlSuggestionServiceTest extends TestCase
{
    public function test_it_writes_ml_suggestions_to_metadata_without_auto_applying(): void
    {
        config([
            'services.ml.enabled' => true,
            'services.ml.url' => 'http://ml-test:8001',
            'services.ml.timeout' => 5,
        ]);

        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
        $counterparty = Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Tesco']);

        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'category_id' => null,
            'counterparty_id' => null,
            'metadata' => ['existing' => true],
        ]);

        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/categorize' => Http::response([
                [
                    'transaction_id' => $transaction->id,
                    'predicted_category_id' => $category->id,
                    'confidence' => 0.91,
                    'method' => 'ml_model',
                    'needs_review' => false,
                ],
            ], 200),
            'ml-test:8001/api/v1/detect-counterparties' => Http::response([
                [
                    'transaction_id' => $transaction->id,
                    'predicted_counterparty_id' => $counterparty->id,
                    'suggested_counterparty_name' => $counterparty->name,
                    'confidence' => 0.88,
                    'method' => 'exact_name_match',
                ],
            ], 200),
        ]);

        $updated = $this->app->make(MlSuggestionService::class)
            ->annotateTransactions((int) $user->id, [$transaction->id]);

        $this->assertSame(1, $updated);

        $transaction->refresh();
        $this->assertNull($transaction->category_id);
        $this->assertNull($transaction->counterparty_id);

        $metadata = (array) $transaction->metadata;
        $this->assertTrue((bool) ($metadata['existing'] ?? false));
        $this->assertSame($category->id, $metadata['ml']['category_suggestion']['id'] ?? null);
        $this->assertSame('Groceries', $metadata['ml']['category_suggestion']['name'] ?? null);
        $this->assertSame('pending', $metadata['ml']['category_suggestion']['status'] ?? null);
        $this->assertSame($counterparty->id, $metadata['ml']['counterparty_suggestion']['id'] ?? null);
        $this->assertSame('Tesco', $metadata['ml']['counterparty_suggestion']['name'] ?? null);
        $this->assertSame('pending', $metadata['ml']['counterparty_suggestion']['status'] ?? null);
        $this->assertNotEmpty($metadata['ml']['suggested_at'] ?? null);
        $this->assertNotEmpty($metadata['ml']['generated_at'] ?? null);
        $this->assertSame('system', $metadata['ml']['origin'] ?? null);
    }

    public function test_it_clears_stale_pending_suggestions_when_ml_returns_none(): void
    {
        config([
            'services.ml.enabled' => true,
            'services.ml.url' => 'http://ml-test:8001',
            'services.ml.timeout' => 5,
        ]);

        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'metadata' => [
                'ml' => [
                    'category_suggestion' => [
                        'id' => 123,
                        'name' => 'Old Category',
                        'status' => 'pending',
                    ],
                    'counterparty_suggestion' => [
                        'id' => null,
                        'name' => 'Old Merchant',
                        'status' => 'pending',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/categorize' => Http::response([], 200),
            'ml-test:8001/api/v1/detect-counterparties' => Http::response([], 200),
        ]);

        $this->app->make(MlSuggestionService::class)
            ->annotateTransactions((int) $user->id, [$transaction->id], 'import');

        $transaction->refresh();
        $metadata = (array) $transaction->metadata;
        $this->assertArrayNotHasKey('ml', $metadata);
    }

    public function test_it_preserves_accepted_suggestion_when_ml_abstains(): void
    {
        config([
            'services.ml.enabled' => true,
            'services.ml.url' => 'http://ml-test:8001',
            'services.ml.timeout' => 5,
        ]);

        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'metadata' => [
                'ml' => [
                    'category_suggestion' => [
                        'id' => 99,
                        'name' => 'Accepted Category',
                        'status' => 'accepted',
                        'accepted_at' => now()->subDay()->toIso8601String(),
                    ],
                ],
            ],
        ]);

        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
            'ml-test:8001/api/v1/categorize' => Http::response([], 200),
            'ml-test:8001/api/v1/detect-counterparties' => Http::response([], 200),
        ]);

        $this->app->make(MlSuggestionService::class)
            ->annotateTransactions((int) $user->id, [$transaction->id], 'gocardless');

        $transaction->refresh();
        $metadata = (array) $transaction->metadata;
        $this->assertSame('accepted', $metadata['ml']['category_suggestion']['status'] ?? null);
        $this->assertSame('Accepted Category', $metadata['ml']['category_suggestion']['name'] ?? null);
        $this->assertSame('gocardless', $metadata['ml']['origin'] ?? null);
    }
}
