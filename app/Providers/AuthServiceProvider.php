<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Merchant;
use App\Policies\CategoryPolicy;
use App\Policies\MerchantPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Category::class => CategoryPolicy::class,
        Merchant::class => MerchantPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Automatically discovers and registers policies
        $this->registerPolicies();
    }
}
