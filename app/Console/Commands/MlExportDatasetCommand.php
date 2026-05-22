<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MlExportDatasetCommand extends Command
{
    private const INTERNAL_COUNTERPARTY_NAMES = [
        'Own Accounts',
        'Revolut FX',
        'Revolut Pocket',
        'Revolut Roundups',
        'Imported Artifact',
    ];

    protected $signature = 'ml:export-dataset
        {task : Dataset type (categories, transfers, partners)}
        {--user= : User ID to export for (all users if omitted)}
        {--output=ml-intern/data : Output directory}
        {--format=jsonl : Output format (jsonl, csv)}
        {--min-samples=50 : Minimum samples per class (categories only)}';

    protected $description = 'Export transaction data as ML training datasets for ml-intern';

    public function handle(): int
    {
        $task = $this->argument('task');
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $outputDir = rtrim((string) $this->option('output'), '/');
        $format = strtolower((string) $this->option('format'));
        $minSamples = (int) $this->option('min-samples');

        if (! in_array($format, ['jsonl', 'csv'], true)) {
            return $this->failCommand("Unknown format: {$format}. Valid: jsonl, csv");
        }

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        return match ($task) {
            'categories' => $this->exportCategories($userId, $outputDir, $format, $minSamples),
            'transfers' => $this->exportTransfers($userId, $outputDir, $format),
            'partners' => $this->exportPartners($userId, $outputDir, $format),
            default => $this->failCommand("Unknown task: {$task}. Valid: categories, transfers, partners"),
        };
    }

    private function exportCategories(?int $userId, string $outputDir, string $format, int $minSamples): int
    {
        $this->info('Exporting categorized transactions...');

        $query = Transaction::query()
            ->whereNotNull('category_id')
            ->whereNotNull('description')
            ->with(['category:id,name', 'account:id,name']);

        if ($userId) {
            $query->whereHas('account', fn ($q) => $q->where('user_id', $userId));
        }

        $transactions = $query->get();

        // Filter classes with too few samples
        $classCounts = $transactions->groupBy('category_id')->map->count();
        $validClasses = $classCounts->filter(fn ($count) => $count >= $minSamples)->keys();

        $rows = [];
        foreach ($transactions as $txn) {
            if (! $validClasses->contains($txn->category_id)) {
                continue;
            }

            $rows[] = [
                'description' => $txn->description ?? '',
                'partner' => $txn->partner ?? '',
                'amount' => (float) $txn->amount,
                'currency' => $txn->currency ?? 'EUR',
                'type' => $txn->type ?? '',
                'category' => $txn->category?->name ?? 'Unknown',
                'category_id' => $txn->category_id,
            ];
        }

        if (empty($rows)) {
            $this->error('No categorized transactions found meeting minimum sample threshold.');

            return self::FAILURE;
        }

        // Shuffle for train/test split later
        shuffle($rows);

        $file = "{$outputDir}/categories.{$format}";
        $this->writeDataset($rows, $file, $format);

        $categoryStats = collect($rows)->groupBy('category')->map->count()->sortDesc();
        $this->info("Exported " . count($rows) . " transactions across {$categoryStats->count()} categories to {$file}");
        $this->table(['Category', 'Count'], $categoryStats->map(fn ($c, $k) => [$k, $c])->values()->all());

        return self::SUCCESS;
    }

    private function exportTransfers(?int $userId, string $outputDir, string $format): int
    {
        $this->info('Exporting transfer detection dataset...');

        $query = Transaction::query()
            ->whereNotNull('description')
            ->with(['account:id,name,user_id']);

        if ($userId) {
            $query->whereHas('account', fn ($q) => $q->where('user_id', $userId));
        }

        $transactions = $query->get();

        $rows = [];
        foreach ($transactions as $txn) {
            $isTransfer = $txn->type === Transaction::TYPE_TRANSFER
                || $txn->transfer_pair_transaction_id !== null;

            $rows[] = [
                'description' => $txn->description ?? '',
                'partner' => $txn->partner ?? '',
                'amount' => (float) $txn->amount,
                'currency' => $txn->currency ?? 'EUR',
                'type' => $txn->type ?? '',
                'source_iban' => $txn->source_iban ?? '',
                'target_iban' => $txn->target_iban ?? '',
                'account_name' => $txn->account?->name ?? '',
                'is_transfer' => $isTransfer,
            ];
        }

        if (empty($rows)) {
            $this->error('No transactions found.');

            return self::FAILURE;
        }

        shuffle($rows);

        $file = "{$outputDir}/transfers.{$format}";
        $this->writeDataset($rows, $file, $format);

        $transferCount = collect($rows)->where('is_transfer', true)->count();
        $nonTransferCount = count($rows) - $transferCount;
        $this->info("Exported " . count($rows) . " transactions ({$transferCount} transfers, {$nonTransferCount} non-transfers) to {$file}");

        return self::SUCCESS;
    }

    private function exportPartners(?int $userId, string $outputDir, string $format): int
    {
        $this->info('Exporting partner normalization dataset...');

        $query = Transaction::query()
            ->whereNotNull('description')
            ->whereNotNull('partner')
            ->whereNotNull('counterparty_id')
            ->where('needs_manual_review', false)
            ->with(['counterparty:id,name,type', 'account:id,name,user_id']);

        if ($userId) {
            $query->whereHas('account', fn ($q) => $q->where('user_id', $userId));
        }

        $transactions = $query->get();

        // Build normalization pairs: raw partner -> counterparty name (user-verified)
        $rows = [];
        foreach ($transactions as $txn) {
            $rawPartner = $txn->partner ?? '';
            $normalizedName = $txn->counterparty?->name;
            $counterpartyType = $txn->counterparty?->type?->value ?? (string) $txn->counterparty?->type;
            $isIncome = (float) $txn->amount > 0;

            if (! $rawPartner || ! $normalizedName || ! $counterpartyType) {
                continue;
            }

            if (! in_array($counterpartyType, ['merchant', 'institution', 'person', 'employer'], true)) {
                continue;
            }

            if (in_array($normalizedName, self::INTERNAL_COUNTERPARTY_NAMES, true)) {
                continue;
            }

            $normalizedLabel = in_array($counterpartyType, ['merchant', 'employer'], true)
                ? sprintf('%s [%s]', $normalizedName, $counterpartyType)
                : $normalizedName;

            $rows[] = [
                'raw_partner' => $rawPartner,
                'raw_description' => $txn->description ?? '',
                'type' => $txn->type ?? '',
                'normalized_name' => $normalizedName,
                'normalized_label' => $normalizedLabel,
                'counterparty_type' => $counterpartyType,
                'amount' => (float) $txn->amount,
                'is_income' => $isIncome,
            ];
        }

        if (empty($rows)) {
            $this->error('No partner normalization pairs found (need transactions with both partner and counterparty).');

            return self::FAILURE;
        }

        // Deduplicate identical pairs
        $unique = collect($rows)
            ->unique(fn ($r) => $r['raw_partner'] . '|' . $r['normalized_label'] . '|' . $r['counterparty_type'] . '|' . ((int) $r['is_income']))
            ->values()
            ->all();
        shuffle($unique);

        $file = "{$outputDir}/partners.{$format}";
        $this->writeDataset($unique, $file, $format);

        $this->info("Exported " . count($unique) . " unique partner normalization pairs to {$file}");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeDataset(array $rows, string $file, string $format): void
    {
        if ($format === 'csv') {
            $fp = fopen($file, 'w');
            if (! empty($rows)) {
                fputcsv($fp, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($fp, $row);
                }
            }
            fclose($fp);
        } else {
            // JSONL
            $fp = fopen($file, 'w');
            foreach ($rows as $row) {
                fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
            }
            fclose($fp);
        }
    }

    private function failCommand(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
