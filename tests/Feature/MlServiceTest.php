<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MlService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MlServiceTest extends TestCase
{
    private function enabledService(string $baseUrl = 'http://ml-test:8001'): MlService
    {
        config([
            'services.ml.enabled' => true,
            'services.ml.url' => $baseUrl,
            'services.ml.timeout' => 5,
        ]);

        return new MlService;
    }

    private function disabledService(): MlService
    {
        config([
            'services.ml.enabled' => false,
            'services.ml.url' => 'http://ml-test:8001',
            'services.ml.timeout' => 5,
        ]);

        return new MlService;
    }

    // -- isAvailable --

    public function test_is_available_returns_true_when_health_ok(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $service = $this->enabledService();

        $this->assertTrue($service->isAvailable());
        Http::assertSentCount(1);
    }

    public function test_is_available_returns_false_on_server_error(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/health' => Http::response('Internal Server Error', 500),
        ]);

        $service = $this->enabledService();

        $this->assertFalse($service->isAvailable());
    }

    public function test_is_available_returns_false_on_connection_error(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/health' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        $service = $this->enabledService();

        $this->assertFalse($service->isAvailable());
    }

    public function test_is_available_returns_false_when_disabled(): void
    {
        Http::fake();

        $service = $this->disabledService();

        $this->assertFalse($service->isAvailable());
        Http::assertNothingSent();
    }

    // -- categorize --

    public function test_categorize_returns_predictions(): void
    {
        $expected = [
            [
                'transaction_id' => 1,
                'predicted_category_id' => 5,
                'confidence' => 0.92,
                'method' => 'ml_model',
                'needs_review' => false,
            ],
            [
                'transaction_id' => 2,
                'predicted_category_id' => 3,
                'confidence' => 0.67,
                'method' => 'keyword',
                'needs_review' => true,
            ],
        ];

        Http::fake([
            'ml-test:8001/api/v1/categorize' => Http::response($expected, 200),
        ]);

        $service = $this->enabledService();
        $result = $service->categorize(userId: 1, transactionIds: [1, 2], limit: 50);

        $this->assertSame($expected, $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://ml-test:8001/api/v1/categorize'
                && $request['user_id'] === 1
                && $request['transaction_ids'] === [1, 2]
                && $request['limit'] === 50;
        });
    }

    public function test_categorize_returns_empty_array_when_disabled(): void
    {
        Http::fake();

        $service = $this->disabledService();
        $result = $service->categorize(userId: 1);

        $this->assertSame([], $result);
        Http::assertNothingSent();
    }

    public function test_categorize_returns_empty_array_on_server_error(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/categorize' => Http::response('Bad Request', 400),
        ]);

        $service = $this->enabledService();
        $result = $service->categorize(userId: 1);

        $this->assertSame([], $result);
    }

    public function test_categorize_returns_empty_array_on_connection_failure(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/categorize' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

        $service = $this->enabledService();
        $result = $service->categorize(userId: 1);

        $this->assertSame([], $result);
    }

    // -- detectMerchants --

    public function test_detect_merchants_returns_predictions(): void
    {
        $expected = [
            [
                'transaction_id' => 10,
                'predicted_merchant_id' => 42,
                'suggested_merchant_name' => 'Netflix',
                'confidence' => 0.95,
                'method' => 'exact_match',
            ],
        ];

        Http::fake([
            'ml-test:8001/api/v1/detect-merchants' => Http::response($expected, 200),
        ]);

        $service = $this->enabledService();
        $result = $service->detectMerchants(userId: 7, transactionIds: [10]);

        $this->assertSame($expected, $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://ml-test:8001/api/v1/detect-merchants'
                && $request['user_id'] === 7
                && $request['transaction_ids'] === [10];
        });
    }

    public function test_detect_merchants_returns_empty_when_disabled(): void
    {
        Http::fake();

        $service = $this->disabledService();

        $this->assertSame([], $service->detectMerchants(userId: 1));
        Http::assertNothingSent();
    }

    // -- detectRecurring --

    public function test_detect_recurring_returns_groups(): void
    {
        $expected = [
            [
                'group_key' => 'netflix_monthly',
                'frequency' => 'monthly',
                'interval_days' => 30.5,
                'confidence' => 0.88,
                'transaction_ids' => [1, 2, 3],
                'amount_stats' => ['mean' => -12.99, 'std' => 0.0],
                'next_expected' => '2026-04-01',
                'anomalies' => [],
            ],
        ];

        Http::fake([
            'ml-test:8001/api/v1/detect-recurring' => Http::response($expected, 200),
        ]);

        $service = $this->enabledService();
        $result = $service->detectRecurring(userId: 5);

        $this->assertSame($expected, $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://ml-test:8001/api/v1/detect-recurring'
                && $request['user_id'] === 5;
        });
    }

    public function test_detect_recurring_returns_empty_when_disabled(): void
    {
        Http::fake();

        $service = $this->disabledService();

        $this->assertSame([], $service->detectRecurring(userId: 1));
        Http::assertNothingSent();
    }

    public function test_detect_recurring_returns_empty_on_500(): void
    {
        Http::fake([
            'ml-test:8001/api/v1/detect-recurring' => Http::response('error', 500),
        ]);

        $service = $this->enabledService();

        $this->assertSame([], $service->detectRecurring(userId: 1));
    }

    // -- disabled fallback covers all post-based methods --

    public function test_all_post_methods_return_empty_when_disabled(): void
    {
        Http::fake();
        $service = $this->disabledService();

        $this->assertSame([], $service->categorize(userId: 1));
        $this->assertSame([], $service->detectMerchants(userId: 1));
        $this->assertSame([], $service->detectRecurring(userId: 1));
        $this->assertSame([], $service->trainCategorizer(userId: 1));
        $this->assertSame([], $service->trainMerchantDetector(userId: 1));
        $this->assertSame([], $service->discoverMerchants(userId: 1));

        Http::assertNothingSent();
    }
}
