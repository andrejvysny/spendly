<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Executes bulk mutations on a set of transactions, enforcing ownership at
 * the service boundary. Foreign-owned IDs are silently dropped (so a mixed
 * request still updates the caller's own rows instead of 403-ing everything).
 */
class TransactionBulkService
{
    /**
     * Fetch the caller's transactions among the given IDs. Foreign IDs are
     * dropped server-side; callers may compare `count($ids)` against the
     * resulting collection's count to report partial application.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Transaction>
     */
    public function ownedTransactions(User $user, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Transaction::whereIn('id', $ids)
            ->whereHas('account', fn ($q) => $q->where('user_id', $user->id))
            ->get();
    }

    /**
     * Apply category / counterparty / recurring_group updates.
     *
     * Target ids that do not belong to $user are silently dropped (the field is
     * left untouched) so a request can never link a transaction to another
     * tenant's category / counterparty / recurring group.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  array{counterparty_id?: ?string, category_id?: ?string, recurring_group_id?: ?string}  $patch
     * @return int  number of rows updated
     */
    public function applyAssignments(Collection $transactions, array $patch, User $user): int
    {
        $patch = $this->dropForeignTargets($patch, $user);
        $updated = 0;
        foreach ($transactions as $transaction) {
            $updateData = [];
            if (array_key_exists('counterparty_id', $patch)) {
                $updateData['counterparty_id'] = $patch['counterparty_id'] === '' ? null : $patch['counterparty_id'];
            }
            if (array_key_exists('category_id', $patch)) {
                $updateData['category_id'] = $patch['category_id'] === '' ? null : $patch['category_id'];
            }
            if (array_key_exists('recurring_group_id', $patch)) {
                $updateData['recurring_group_id'] = $patch['recurring_group_id'] === '' ? null : $patch['recurring_group_id'];
            }
            if ($updateData === []) {
                continue;
            }
            $transaction->update($updateData);
            $updated++;
        }

        return $updated;
    }

    /**
     * Remove any assignment target id that the user does not own (empty/null is
     * kept — clearing an assignment is always allowed).
     *
     * @param  array{counterparty_id?: ?string, category_id?: ?string, recurring_group_id?: ?string}  $patch
     * @return array{counterparty_id?: ?string, category_id?: ?string, recurring_group_id?: ?string}
     */
    private function dropForeignTargets(array $patch, User $user): array
    {
        $tables = [
            'category_id' => 'categories',
            'counterparty_id' => 'counterparties',
            'recurring_group_id' => 'recurring_groups',
        ];

        foreach ($tables as $field => $table) {
            if (! array_key_exists($field, $patch)) {
                continue;
            }
            $value = $patch[$field];
            if ($value === null || $value === '') {
                continue;
            }
            $owned = DB::table($table)
                ->where('id', $value)
                ->where('user_id', $user->id)
                ->exists();
            if (! $owned) {
                unset($patch[$field]);
            }
        }

        return $patch;
    }

    /**
     * Replace or append a note across the given transactions.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{id: int, note: ?string}>
     */
    public function applyNotes(Collection $transactions, string $note, string $method): array
    {
        $rows = [];
        foreach ($transactions as $transaction) {
            if ($method === 'replace') {
                $transaction->update(['note' => $note]);
            } elseif ($method === 'append') {
                $existing = $transaction->note ?? '';
                $combined = $existing !== '' ? $existing."\n".$note : $note;
                $transaction->update(['note' => $combined]);
            }
            $transaction->refresh();
            $rows[] = ['id' => (int) $transaction->id, 'note' => $transaction->note];
        }

        return $rows;
    }

    /**
     * Add / remove / set tags on the given transactions.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<int, int|string>  $tagIds
     * @return array<int, array{id: int, tags: mixed}>
     */
    public function applyTags(Collection $transactions, array $tagIds, string $mode): array
    {
        $rows = [];
        foreach ($transactions as $transaction) {
            match ($mode) {
                'add' => $transaction->tags()->syncWithoutDetaching($tagIds),
                'remove' => $transaction->tags()->detach($tagIds),
                'set' => $transaction->tags()->sync($tagIds),
                default => null,
            };

            $transaction->load('tags');
            $rows[] = ['id' => (int) $transaction->id, 'tags' => $transaction->tags];
        }

        return $rows;
    }

    /**
     * Update the type and optionally auto-pair two transactions whose amounts
     * sum to ~0 as a transfer pair.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array{paired: bool}
     */
    public function applyType(Collection $transactions, string $type, bool $clearTransferPair): array
    {
        $paired = false;

        DB::transaction(function () use ($transactions, $type, $clearTransferPair, &$paired): void {
            if ($clearTransferPair) {
                /** @var array<int, int> $partnerIds */
                $partnerIds = $transactions->pluck('transfer_pair_transaction_id')->filter()->values()->all();

                Transaction::whereIn('id', $transactions->pluck('id'))
                    ->update(['transfer_pair_transaction_id' => null]);

                if ($partnerIds !== []) {
                    // The partner legs are now orphaned transfers (type=TRANSFER, no pair);
                    // flag them so they don't silently stay out of income/expense totals.
                    Transaction::whereIn('id', $partnerIds)->update([
                        'transfer_pair_transaction_id' => null,
                        'needs_manual_review' => true,
                        'review_reason' => 'Transfer pair removed — reclassify this transaction',
                    ]);
                }

                $transactions->each->refresh();
            }

            foreach ($transactions as $transaction) {
                $transaction->update(['type' => $type]);
            }

            if ($type === Transaction::TYPE_TRANSFER && $transactions->count() === 2) {
                /** @var Transaction|null $first */
                $first = $transactions->first();
                /** @var Transaction|null $second */
                $second = $transactions->last();
                if ($first === null || $second === null) {
                    return;
                }
                if (abs((float) $first->amount + (float) $second->amount) <= 0.01) {
                    $first->update(['transfer_pair_transaction_id' => $second->id]);
                    $second->update(['transfer_pair_transaction_id' => $first->id]);
                    $paired = true;
                }
            }
        });

        return ['paired' => $paired];
    }

    /**
     * Delete transactions, detaching tags and clearing partner transfer pairs.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, int>  Deleted ids
     */
    public function deleteAll(Collection $transactions): array
    {
        $deletedIds = [];

        DB::transaction(function () use ($transactions, &$deletedIds): void {
            /** @var array<int, int> $partnerIds */
            $partnerIds = $transactions->pluck('transfer_pair_transaction_id')->filter()->values()->all();
            $deletingIds = $transactions->pluck('id')->map(fn ($id) => (int) $id)->all();
            // Don't flag partners that are themselves being deleted in this batch.
            $orphanedPartnerIds = array_values(array_diff($partnerIds, $deletingIds));

            if ($orphanedPartnerIds !== []) {
                // Surviving partner legs become orphaned transfers; flag for reclassification
                // so they re-enter the user's attention instead of vanishing from totals.
                Transaction::whereIn('id', $orphanedPartnerIds)->update([
                    'transfer_pair_transaction_id' => null,
                    'needs_manual_review' => true,
                    'review_reason' => 'Transfer pair deleted — reclassify this transaction',
                ]);
            }

            foreach ($transactions as $transaction) {
                $deletedIds[] = (int) $transaction->id;
                $transaction->tags()->detach();
                $transaction->delete();
            }
        });

        return $deletedIds;
    }
}
