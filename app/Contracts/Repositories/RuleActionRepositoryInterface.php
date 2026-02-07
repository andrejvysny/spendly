<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleAction;

/**
 * @extends RuleScopedRepositoryInterface<RuleAction>
 */
interface RuleActionRepositoryInterface extends RuleScopedRepositoryInterface
{
}
