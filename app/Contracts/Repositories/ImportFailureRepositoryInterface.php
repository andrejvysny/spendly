<?php

namespace App\Contracts\Repositories;

use App\Models\Import\ImportFailure;

interface ImportFailureRepositoryInterface extends BaseRepositoryContract
{
    /** @return int */
    public function createBatch(array $failures): int;
    public function createOne(array $data): ImportFailure;
}
