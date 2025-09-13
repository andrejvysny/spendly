<?php

namespace App\Providers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\ConditionGroupRepositoryInterface;
use App\Contracts\Repositories\ImportMappingRepositoryInterface;
use App\Contracts\Repositories\ImportRepositoryInterface;
use App\Contracts\Repositories\MerchantRepositoryInterface;
use App\Contracts\Repositories\RuleActionRepositoryInterface;
use App\Contracts\Repositories\RuleConditionRepositoryInterface;
use App\Contracts\Repositories\RuleExecutionLogRepositoryInterface;
use App\Contracts\Repositories\RuleGroupRepositoryInterface;
use App\Contracts\Repositories\RuleRepositoryInterface;
use App\Contracts\Repositories\TagRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Contracts\Repositories\ImportFailureRepositoryInterface;
use App\Contracts\Repositories\TransactionRuleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ConditionGroupRepository;
use App\Repositories\ImportMappingRepository;
use App\Repositories\ImportRepository;
use App\Repositories\MerchantRepository;
use App\Repositories\RuleActionRepository;
use App\Repositories\RuleConditionRepository;
use App\Repositories\RuleExecutionLogRepository;
use App\Repositories\RuleGroupRepository;
use App\Repositories\RuleRepository;
use App\Repositories\TagRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\ImportFailureRepository;
use App\Repositories\TransactionRuleRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Core repositories
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(AccountRepositoryInterface::class, AccountRepository::class);
        $this->app->bind(TransactionRepositoryInterface::class, TransactionRepository::class);
        
        // Category and Tag repositories
        $this->app->bind(CategoryRepositoryInterface::class, CategoryRepository::class);
        $this->app->bind(TagRepositoryInterface::class, TagRepository::class);
        $this->app->bind(MerchantRepositoryInterface::class, MerchantRepository::class);
        
        // Import repositories
        $this->app->bind(ImportRepositoryInterface::class, ImportRepository::class);
        $this->app->bind(ImportMappingRepositoryInterface::class, ImportMappingRepository::class);
        $this->app->bind(ImportFailureRepositoryInterface::class, ImportFailureRepository::class);
        
        // Rule engine repositories
        $this->app->bind(RuleRepositoryInterface::class, RuleRepository::class);
        $this->app->bind(RuleActionRepositoryInterface::class, RuleActionRepository::class);
        $this->app->bind(RuleConditionRepositoryInterface::class, RuleConditionRepository::class);
        $this->app->bind(RuleGroupRepositoryInterface::class, RuleGroupRepository::class);
        $this->app->bind(ConditionGroupRepositoryInterface::class, ConditionGroupRepository::class);
        $this->app->bind(RuleExecutionLogRepositoryInterface::class, RuleExecutionLogRepository::class);
        $this->app->bind(TransactionRuleRepositoryInterface::class, TransactionRuleRepository::class);
    }
}
