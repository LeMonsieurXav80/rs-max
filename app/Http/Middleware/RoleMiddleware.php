<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role = 'admin'): Response
    {
        if (! $request->user()?->isAtLeast($role)) {
            abort(403, 'Acces insuffisant.');
        }

        return $next($request);
    }
}
