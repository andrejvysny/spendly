<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ImportMappingRepositoryInterface;
use App\Models\Import\ImportMapping;
use App\Repositories\Concerns\UserScoped;
use Illuminate\Support\Collection;

class ImportMappingRepository extends BaseRepository implements ImportMappingRepositoryInterface
{
    use UserScoped;

    public function __construct(ImportMapping $model)
    {
        parent::__construct($model);
    }

    /**
     * @return Collection<int, ImportMapping>
     */
    public function findByUserAndBankProvider(int $userId, string $bankProvider): Collection
    {
        return $this->model->where('user_id', $userId)
            ->where('bank_provider', $bankProvider)
            ->get();
    }
}
