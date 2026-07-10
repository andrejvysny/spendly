<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill native_amount for same-currency transactions (the common case):
        //    when a transaction's currency equals its owner's base currency, the
        //    native amount equals the original amount. Cross-currency rows are left
        //    null on purpose — they need historical FX rates and are handled at read
        //    time / by review, not by fabricating a conversion here.
        $accounts = DB::table('accounts')
            ->join('users', 'accounts.user_id', '=', 'users.id')
            ->select('accounts.id as account_id', 'users.base_currency as base_currency')
            ->get();

        foreach ($accounts as $account) {
            $base = $account->base_currency ?: 'EUR';
            DB::table('transactions')
                ->where('account_id', $account->account_id)
                ->whereNull('native_amount')
                ->where('currency', $base)
                ->update(['native_amount' => DB::raw('amount')]);
        }

        // 2. Deactivate orphaned category budgets (category deleted -> category_id null):
        //    these would otherwise behave as "all spending" budgets and now match
        //    nothing, so flag them inactive for the user to fix/recreate.
        DB::table('budgets')
            ->where('target_type', 'category')
            ->whereNull('category_id')
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        // Non-reversible data backfill; nothing to undo.
    }
};
