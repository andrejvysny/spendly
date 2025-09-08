<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Import;
use App\Models\Merchant;
use App\Models\Tag;
use App\Policies\CategoryPolicy;
use App\Policies\ImportPolicy;
use App\Policies\MerchantPolicy;
use App\Policies\TagPolicy;
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
        Import::class => ImportPolicy::class,
        Merchant::class => MerchantPolicy::class,
        Tag::class => TagPolicy::class,
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
