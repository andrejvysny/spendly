<?php

namespace App\Providers;

use App\Contracts\RuleEngine\ActionExecutorInterface;
use App\Contracts\RuleEngine\ConditionEvaluatorInterface;
use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Listeners\ProcessTransactionRules;
use App\Listeners\ProcessTransactionRulesSync;
use App\Services\RuleEngine\ActionExecutor;
use App\Services\RuleEngine\ConditionEvaluator;
use App\Services\RuleEngine\RuleEngine;
use Illuminate\Support\ServiceProvider;

class RuleEngineServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind interfaces to implementations
        $this->app->bind(ConditionEvaluatorInterface::class, ConditionEvaluator::class);
        $this->app->bind(ActionExecutorInterface::class, ActionExecutor::class);

        // Register the rule engine as a singleton for better performance
        $this->app->singleton(RuleEngineInterface::class, function ($app) {
            return new RuleEngine(
                $app->make(ConditionEvaluatorInterface::class),
                $app->make(ActionExecutorInterface::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register event listeners based on processing mode
        $events = $this->app->make('events');

        if (config('ruleengine.processing_mode') === 'sync') {
            $events->subscribe(ProcessTransactionRulesSync::class);
        } else {
            $events->subscribe(ProcessTransactionRules::class);
        }
    }
}
