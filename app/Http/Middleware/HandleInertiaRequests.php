<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $shared = [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];

        // Only add facade-dependent data if the application is bootstrapped
        if (app()->isBooted()) {
            try {
                [$message, $author] = str(Inspiring::quotes()->random())->explode('-');
                $shared['quote'] = ['message' => trim($message), 'author' => trim($author)];
                $shared['name'] = config('app.name');
                $shared['ziggy'] = fn (): array => [
                    ...(new Ziggy)->toArray(),
                    'location' => $request->url(),
                ];
            } catch (\Exception $e) {
                // Fallback if facades are not available
                $shared['quote'] = ['message' => 'Build something amazing.', 'author' => 'Laravel'];
                $shared['name'] = 'Laravel';
            }
        }

        return $shared;
    }
}
