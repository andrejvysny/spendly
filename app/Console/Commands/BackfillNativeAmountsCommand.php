<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillNativeAmountsCommand extends Command
{
    protected $signature = 'native-amounts:backfill {--user= : Backfill for specific user ID}';

    protected $description = 'Backfill native_amount for transactions missing it';

    public function handle(ExchangeRateService $exchangeRateService): int
    {
        $userId = $this->option('user');

        $query = Transaction::whereNull('native_amount')
            ->with('account:id,user_id,currency');

        if ($userId !== null) {
            $accountIds = User::findOrFail($userId)->accounts()->pluck('id');
            $query->whereIn('account_id', $accountIds);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('No transactions need backfilling.');

            return self::SUCCESS;
        }

        $this->info("Backfilling native_amount for {$total} transactions...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        /** @var array<int, string> $userCurrencies */
        $userCurrencies = [];
        if ($userId !== null) {
            $user = User::findOrFail($userId);
            $userCurrencies[(int) $user->id] = $user->base_currency ?? 'EUR';
        }

        $processed = 0;

        $query->chunkById(500, function ($transactions) use ($exchangeRateService, &$userCurrencies, $bar, &$processed): void {
            /** @var array<int, float> $updates */
            $updates = [];

            foreach ($transactions as $transaction) {
                $account = $transaction->account;
                if ($account === null) {
                    $bar->advance();

                    continue;
                }

                /** @var int $ownerId */
                $ownerId = $account->getAttribute('user_id');
                if (! isset($userCurrencies[$ownerId])) {
                    $user = User::find($ownerId);
                    $userCurrencies[$ownerId] = $user->base_currency ?? 'EUR';
                }

                $baseCurrency = $userCurrencies[$ownerId];
                $txCurrency = $transaction->currency ?? $baseCurrency;
                /** @var Carbon $bookedDate */
                $bookedDate = $transaction->booked_date;

                if ($txCurrency === $baseCurrency) {
                    $nativeAmount = (float) $transaction->amount;
                } else {
                    $nativeAmount = $exchangeRateService->convert(
                        (float) $transaction->amount,
                        $txCurrency,
                        $baseCurrency,
                        $bookedDate
                    );
                }

                $updates[(int) $transaction->id] = $nativeAmount;
                $bar->advance();
                $processed++;
            }

            // Batch update within a single transaction
            if ($updates !== []) {
                DB::transaction(function () use ($updates): void {
                    foreach ($updates as $id => $nativeAmount) {
                        DB::table('transactions')
                            ->where('id', $id)
                            ->update(['native_amount' => $nativeAmount]);
                    }
                });
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Backfilled {$processed} transactions.");

        return self::SUCCESS;
    }
}
