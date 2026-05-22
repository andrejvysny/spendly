<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_superadmin !== true) {
            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
