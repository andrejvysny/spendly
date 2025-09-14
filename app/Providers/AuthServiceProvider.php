<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Import\Import;
use App\Models\Merchant;
use App\Models\Tag;
use App\Policies\OwnedByUserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Category::class => OwnedByUserPolicy::class,
        Import::class => OwnedByUserPolicy::class,
        Merchant::class => OwnedByUserPolicy::class,
        Tag::class => OwnedByUserPolicy::class,
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
