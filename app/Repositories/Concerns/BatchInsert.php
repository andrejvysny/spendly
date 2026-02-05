<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use Illuminate\Support\Facades\DB;

trait BatchInsert
{
    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $jsonColumns
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

        DB::table($table)->insert($processed);

        return count($processed);
    }
}
