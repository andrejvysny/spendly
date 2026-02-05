<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleCondition;

/**
 * @extends RuleScopedRepositoryInterface<RuleCondition>
 */
interface RuleConditionRepositoryInterface extends RuleScopedRepositoryInterface
{
}
