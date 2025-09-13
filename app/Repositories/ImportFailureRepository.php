<?php

namespace App\Repositories;

use App\Contracts\Repositories\ImportFailureRepositoryInterface;
use App\Models\Import\ImportFailure;
use Illuminate\Support\Facades\DB;

class ImportFailureRepository extends BaseRepository implements ImportFailureRepositoryInterface
{
    public function __construct(ImportFailure $model)
    {
        parent::__construct($model);
    }

    public function createBatch(array $failures): int
    {
        if (empty($failures)) {
            return 0;
        }

        // Ensure JSON columns are encoded
        $processed = array_map(function ($row) {
            foreach (['raw_data', 'error_details', 'parsed_data', 'metadata'] as $jsonField) {
                if (isset($row[$jsonField]) && is_array($row[$jsonField])) {
                    $row[$jsonField] = json_encode($row[$jsonField]);
                }
            }
            return $row;
        }, $failures);

        DB::table('import_failures')->insert($processed);

        return count($processed);
    }

    public function createOne(array $data): ImportFailure
    {
        return ImportFailure::create($data);
    }
}
