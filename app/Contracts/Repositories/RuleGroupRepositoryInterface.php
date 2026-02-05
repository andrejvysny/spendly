<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleGroup;

/**
 * @extends UserScopedRepositoryInterface<RuleGroup>
 */
interface RuleGroupRepositoryInterface extends UserScopedRepositoryInterface
{
}
