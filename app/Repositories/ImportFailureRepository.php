<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ImportFailureRepositoryInterface;
use App\Models\Import\ImportFailure;
use App\Repositories\Concerns\BatchInsert;

class ImportFailureRepository extends BaseRepository implements ImportFailureRepositoryInterface
{
    use BatchInsert;

    public function __construct(ImportFailure $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<mixed>  $failures
     */
    public function createBatch(array $failures): int
    {
        return $this->batchInsert(
            'import_failures',
            $failures,
            ['raw_data', 'error_details', 'parsed_data', 'metadata']
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOne(array $data): ImportFailure
    {
        $model = $this->model->create($data);

        return $model instanceof ImportFailure ? $model : $this->model->find($model->getKey());
    }
}
