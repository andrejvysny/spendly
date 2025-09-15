<?php

namespace App\Contracts\Repositories;

use App\Models\Import\ImportFailure;

interface ImportFailureRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param  array<mixed>  $failures
     */
    public function createBatch(array $failures): int;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOne(array $data): ImportFailure;
}
