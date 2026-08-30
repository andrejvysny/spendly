<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use Illuminate\Support\Facades\DB;

trait BatchInsert
{
    /**
     * Insert records, ignoring rows rejected by a unique constraint.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $jsonColumns
     * @return int Number of rows actually inserted (dropped duplicates are not counted).
     */
    protected function batchInsert(string $table, array $records, array $jsonColumns = []): int
    {
        if (empty($records)) {
            return 0;
        }

        $processed = array_map(function (array $record) use ($jsonColumns): array {
            foreach ($jsonColumns as $column) {
                if (isset($record[$column]) && is_array($record[$column])) {
                    $record[$column] = json_encode($record[$column]);
                }
            }

            return $record;
        }, $records);

        return DB::table($table)->insertOrIgnore($processed);
    }
}
