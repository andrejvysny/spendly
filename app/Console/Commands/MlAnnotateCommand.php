<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\MlSuggestionService;
use Illuminate\Console\Command;

class MlAnnotateCommand extends Command
{
    protected $signature = 'ml:annotate
        {--user= : User ID to annotate transactions for}
        {--limit=500 : Maximum number of transactions to annotate}
        {--all : Include already-categorized transactions (refresh suggestions)}';

    protected $description = 'Write ML suggestions into transaction metadata for existing transactions';

    public function handle(MlSuggestionService $suggestions): int
    {
        $userId = (int) $this->option('user');

        if (! $userId) {
            $this->error('--user is required');

            return self::FAILURE;
        }

        $query = Transaction::query()
            ->whereHas('account', fn ($q) => $q->where('user_id', $userId))
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->orderByDesc('booked_date');

        if (! $this->option('all')) {
            $query->whereNull('category_id');
        }

        $ids = $query->limit((int) $this->option('limit'))
            ->pluck('id')
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($ids === []) {
            $this->info('Nothing to annotate.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Annotating %d transactions for user #%d...', count($ids), $userId));

        $updated = $suggestions->annotateTransactions($userId, $ids, 'cli');

        $this->info("Suggestions written to {$updated} transactions.");

        return self::SUCCESS;
    }
}
