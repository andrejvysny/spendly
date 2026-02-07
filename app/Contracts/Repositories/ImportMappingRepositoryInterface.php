<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Import\ImportMapping;
use Illuminate\Support\Collection;

/**
 * @extends NamedRepositoryInterface<ImportMapping>
 */
interface ImportMappingRepositoryInterface extends NamedRepositoryInterface
{
    /**
     * @return Collection<int, ImportMapping>
     */
    public function findByUserAndBankProvider(int $userId, string $bankProvider): Collection;
}
