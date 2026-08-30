<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Translates an incoming HTTP request into a scoped, filtered Transaction query.
 *
 * Owns the previously-inline filter logic from TransactionController so it can
 * be reused by the index, filter, and load-more endpoints without duplication.
 */
class TransactionFilterService
{
    /**
     * Coerce a request value to string ('' for missing/non-scalar).
     */
    private function strInput(Request $request, string $key): string
    {
        $raw = $request->input($key);

        return is_scalar($raw) ? (string) $raw : '';
    }

    /**
     * Build a user-scoped Transaction query and apply request filters.
     *
     * @return array{0: Builder<Transaction>, 1: bool} [query, isFiltered]
     */
    public function buildQuery(User $user, Request $request): array
    {
        $userAccountIds = $user->accounts()->pluck('id');

        /** @var Builder<Transaction> $query */
        $query = Transaction::with(['account', 'counterparty', 'category', 'tags'])
            ->whereIn('account_id', $userAccountIds)
            ->orderBy('booked_date', 'desc');

        $isFiltered = $this->applyFilters($query, $request);

        return [$query, $isFiltered];
    }

    /**
     * Apply filtering parameters to the provided query instance.
     *
     * @param  Builder<Transaction>  $query
     */
    private function applyFilters(Builder $query, Request $request): bool
    {
        $isFiltered = false;

        if ($request->has('search') && ! empty($request->search)) {
            /** @phpstan-ignore-next-line — search() is defined on Transaction model as a local scope */
            $query->search((string) $request->search);
            $isFiltered = true;
        }

        if ($request->has('transactionType') && ! empty($request->transactionType) && $request->transactionType !== 'all') {
            switch ($request->transactionType) {
                case 'income':
                    $query->where('amount', '>', 0);
                    break;
                case 'expense':
                    $query->where('amount', '<', 0);
                    break;
                case 'transfer':
                    $query->where('type', 'TRANSFER');
                    break;
            }
            $isFiltered = true;
        }

        if ($request->has('account_id') && ! empty($request->account_id) && $request->account_id !== 'all') {
            $query->where('account_id', $request->account_id);
            $isFiltered = true;
        }

        if ($request->has('amountFilterType') && ! empty($request->amountFilterType) && $request->amountFilterType !== 'all') {
            $isFiltered = true;
            $this->applyAmountFilter($query, $request);
        } else {
            $isFiltered = $this->applyLegacyAmountFilter($query, $request) || $isFiltered;
        }

        if ($request->has('counterparty_id') && ! empty($request->counterparty_id) && $request->counterparty_id !== 'all') {
            $query->where('counterparty_id', $request->counterparty_id);
            $isFiltered = true;
        }

        if ($request->has('category_id') && ! empty($request->category_id) && $request->category_id !== 'all') {
            $query->where('category_id', $request->category_id);
            $isFiltered = true;
        }

        if ($request->has('dateFrom') && ! empty($request->dateFrom)) {
            $query->whereDate('booked_date', '>=', $this->strInput($request, 'dateFrom'));
            $isFiltered = true;
        }

        if ($request->has('dateTo') && ! empty($request->dateTo)) {
            $query->whereDate('booked_date', '<=', $this->strInput($request, 'dateTo'));
            $isFiltered = true;
        }

        if ($request->boolean('recurring_only')) {
            $query->whereNotNull('recurring_group_id');
            $isFiltered = true;
        }

        if ($request->boolean('unlinked_only')) {
            $query->whereNull('recurring_group_id');
            $isFiltered = true;
        }

        return $isFiltered;
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function applyAmountFilter(Builder $query, Request $request): void
    {
        /** @var mixed $rawType */
        $rawType = $request->transactionType;
        $transactionType = is_string($rawType) ? $rawType : 'all';

        switch ($request->amountFilterType) {
            case 'exact':
                if ($request->has('amountExact') && $this->strInput($request, 'amountExact') !== '') {
                    $exact = abs((float) $this->strInput($request, 'amountExact'));
                    if ($transactionType === 'income') {
                        $query->where('amount', $exact);
                    } elseif ($transactionType === 'expense') {
                        $query->where('amount', -$exact);
                    } else {
                        $query->where(function ($q) use ($exact): void {
                            $q->where('amount', $exact)->orWhere('amount', -$exact);
                        });
                    }
                }
                break;

            case 'range':
                if ($request->has('amountMin') && ! empty($request->amountMin)) {
                    $min = abs((float) $this->strInput($request, 'amountMin'));
                    if ($transactionType === 'income') {
                        $query->where('amount', '>=', $min);
                    } elseif ($transactionType === 'expense') {
                        $query->where('amount', '<=', -$min);
                    } else {
                        $query->where(function ($q) use ($min): void {
                            $q->where(fn ($sq) => $sq->where('amount', '>=', $min))
                                ->orWhere(fn ($sq) => $sq->where('amount', '<=', -$min));
                        });
                    }
                }
                if ($request->has('amountMax') && ! empty($request->amountMax)) {
                    $max = abs((float) $this->strInput($request, 'amountMax'));
                    if ($transactionType === 'income') {
                        $query->where('amount', '<=', $max);
                    } elseif ($transactionType === 'expense') {
                        $query->where('amount', '>=', -$max);
                    } else {
                        $query->where(function ($q) use ($max): void {
                            $q->where(fn ($sq) => $sq->where('amount', '<=', $max)->where('amount', '>', 0))
                                ->orWhere(fn ($sq) => $sq->where('amount', '>=', -$max)->where('amount', '<', 0));
                        });
                    }
                }
                break;

            case 'above':
                if ($request->has('amountAbove') && ! empty($request->amountAbove)) {
                    $above = abs((float) $this->strInput($request, 'amountAbove'));
                    if ($transactionType === 'income') {
                        $query->where('amount', '>=', $above);
                    } elseif ($transactionType === 'expense') {
                        $query->where('amount', '<=', -$above);
                    } else {
                        $query->where(function ($q) use ($above): void {
                            $q->where(fn ($sq) => $sq->where('amount', '>=', $above))
                                ->orWhere(fn ($sq) => $sq->where('amount', '<=', -$above));
                        });
                    }
                }
                break;

            case 'below':
                if ($request->has('amountBelow') && ! empty($request->amountBelow)) {
                    $below = abs((float) $this->strInput($request, 'amountBelow'));
                    if ($transactionType === 'income') {
                        $query->where('amount', '<=', $below)->where('amount', '>', 0);
                    } elseif ($transactionType === 'expense') {
                        $query->where('amount', '>=', -$below)->where('amount', '<', 0);
                    } else {
                        $query->where(function ($q) use ($below): void {
                            $q->where(fn ($sq) => $sq->where('amount', '<=', $below)->where('amount', '>', 0))
                                ->orWhere(fn ($sq) => $sq->where('amount', '>=', -$below)->where('amount', '<', 0));
                        });
                    }
                }
                break;
        }
    }

    /**
     * Legacy fallback: amountMin / amountMax when amountFilterType is not set.
     *
     * @param  Builder<Transaction>  $query
     */
    private function applyLegacyAmountFilter(Builder $query, Request $request): bool
    {
        $isFiltered = false;
        /** @var mixed $rawType */
        $rawType = $request->transactionType;
        $transactionType = is_string($rawType) ? $rawType : 'all';

        if ($request->has('amountMin') && ! empty($request->amountMin)) {
            $min = abs((float) $this->strInput($request, 'amountMin'));
            if ($transactionType === 'income') {
                $query->where('amount', '>=', $min);
            } elseif ($transactionType === 'expense') {
                $query->where('amount', '<=', -$min);
            } else {
                $query->where(function ($q) use ($min): void {
                    $q->where(fn ($sq) => $sq->where('amount', '>=', $min))
                        ->orWhere(fn ($sq) => $sq->where('amount', '<=', -$min));
                });
            }
            $isFiltered = true;
        }

        if ($request->has('amountMax') && ! empty($request->amountMax)) {
            $max = abs((float) $this->strInput($request, 'amountMax'));
            if ($transactionType === 'income') {
                $query->where('amount', '<=', $max);
            } elseif ($transactionType === 'expense') {
                $query->where('amount', '>=', -$max);
            } else {
                $query->where(function ($q) use ($max): void {
                    $q->where(fn ($sq) => $sq->where('amount', '<=', $max)->where('amount', '>', 0))
                        ->orWhere(fn ($sq) => $sq->where('amount', '>=', -$max)->where('amount', '<', 0));
                });
            }
            $isFiltered = true;
        }

        if ($request->has('amountExact') && ! empty($request->amountExact)) {
            $isFiltered = true;
        }
        if ($request->has('amountAbove') && ! empty($request->amountAbove)) {
            $isFiltered = true;
        }
        if ($request->has('amountBelow') && ! empty($request->amountBelow)) {
            $isFiltered = true;
        }

        return $isFiltered;
    }
}
