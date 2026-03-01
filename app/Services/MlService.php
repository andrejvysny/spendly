<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MlService
{
    private string $baseUrl;
    private bool $enabled;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ml.url', env('ML_API_URL', 'http://localhost:8001')), '/');
        $this->enabled = (bool) config('services.ml.enabled', env('ML_ENABLED', false));
        $this->timeout = (int) config('services.ml.timeout', 30);
    }

    public function isAvailable(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/v1/health");
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  int[]|null  $transactionIds
     * @return array<int, array{transaction_id: int, predicted_category_id: int, confidence: float, method: string, needs_review: bool}>
     */
    public function categorize(int $userId, ?array $transactionIds = null, int $limit = 100): array
    {
        return $this->post('/api/v1/categorize', [
            'user_id' => $userId,
            'transaction_ids' => $transactionIds,
            'limit' => $limit,
        ]);
    }

    /**
     * @param  int[]|null  $transactionIds
     * @return array<int, array{transaction_id: int, predicted_merchant_id: ?int, suggested_merchant_name: ?string, confidence: float, method: string}>
     */
    public function detectMerchants(int $userId, ?array $transactionIds = null, int $limit = 100): array
    {
        return $this->post('/api/v1/detect-merchants', [
            'user_id' => $userId,
            'transaction_ids' => $transactionIds,
            'limit' => $limit,
        ]);
    }

    /**
     * @return array<int, array{group_key: string, frequency: string, interval_days: float, confidence: float, transaction_ids: int[], amount_stats: array, next_expected: ?string, anomalies: array}>
     */
    public function detectRecurring(int $userId): array
    {
        return $this->post('/api/v1/detect-recurring', [
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array{status: string, message: string, metrics?: array}
     */
    public function trainCategorizer(int $userId): array
    {
        return $this->post('/api/v1/train/categorizer', [
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array{status: string, message: string}
     */
    public function trainMerchantDetector(int $userId): array
    {
        return $this->post('/api/v1/train/merchant-detector', [
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array<int, array{transaction_id: int, is_transfer: bool, confidence: float, method: string, suggested_pair_id: ?int}>
     */
    public function detectTransfers(int $userId, int $limit = 500): array
    {
        return $this->post('/api/v1/detect-transfers', [
            'user_id' => $userId,
            'limit' => $limit,
        ]);
    }

    /**
     * @return array{status: string, message: string, metrics?: array}
     */
    public function trainTransferDetector(int $userId): array
    {
        return $this->post('/api/v1/train/transfer-detector', [
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array<int, array{cluster_id: int, suggested_name: string, transaction_ids: int[], confidence: float}>
     */
    public function discoverMerchants(int $userId): array
    {
        return $this->post('/api/v1/discover-merchants', [
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, array $data): array
    {
        if (! $this->enabled) {
            Log::debug('ML service disabled, skipping request', ['path' => $path]);
            return [];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post("{$this->baseUrl}{$path}", $data);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            Log::warning('ML service returned error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        } catch (\Throwable $e) {
            Log::warning('ML service unavailable', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
