<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks sign-up when the instance has registration turned off.
 *
 * The routes stay registered rather than being removed, so Ziggy's route('register')
 * still resolves on the login page; the link is hidden via the shared
 * `registrationEnabled` Inertia prop instead. A 404 is deliberate — a 403 would
 * confirm to a stranger that the endpoint exists and is merely closed.
 */
class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('auth.registration_enabled'), 404);

        return $next($request);
    }
}
