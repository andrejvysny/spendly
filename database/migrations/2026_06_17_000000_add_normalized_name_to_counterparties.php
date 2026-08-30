<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counterparties', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('name');
        });

        // Backfill normalized_name for existing rows.
        foreach (DB::table('counterparties')->select('id', 'name')->get() as $row) {
            DB::table('counterparties')
                ->where('id', $row->id)
                ->update(['normalized_name' => $this->normalize((string) $row->name)]);
        }

        // Consolidate existing duplicates so the unique index can be added:
        // keep the lowest id per (user_id, normalized_name), repoint references, delete the rest.
        $groups = DB::table('counterparties')
            ->select('user_id', 'normalized_name', DB::raw('COUNT(*) as cnt'), DB::raw('MIN(id) as keep_id'))
            ->groupBy('user_id', 'normalized_name')
            // havingRaw('COUNT(*)'), not having('cnt'): PostgreSQL does not resolve
            // select-list aliases in HAVING, so the alias form fails with
            // 'column "cnt" does not exist'. SQLite and MySQL accept both.
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $keepId = (int) $group->keep_id;
            $dupeIds = DB::table('counterparties')
                ->where('user_id', $group->user_id)
                ->where('normalized_name', $group->normalized_name)
                ->where('id', '!=', $keepId)
                ->pluck('id')
                ->all();

            if ($dupeIds === []) {
                continue;
            }

            DB::table('transactions')->whereIn('counterparty_id', $dupeIds)->update(['counterparty_id' => $keepId]);
            if (Schema::hasColumn('budgets', 'counterparty_id')) {
                DB::table('budgets')->whereIn('counterparty_id', $dupeIds)->update(['counterparty_id' => $keepId]);
            }
            DB::table('counterparties')->whereIn('id', $dupeIds)->delete();
        }

        Schema::table('counterparties', function (Blueprint $table) {
            $table->unique(['user_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
};
