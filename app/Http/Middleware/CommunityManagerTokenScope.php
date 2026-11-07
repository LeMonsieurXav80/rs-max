<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** Le jeton du robot ne donne accès ni aux réglages, ni aux cookies, ni aux publicités. */
class CommunityManagerTokenScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && in_array('community-manager', (array) $token->abilities, true)) {
            abort_unless($request->is('api/community-manager/*'), 403, 'Jeton réservé à Community Manager.');
        }

        return $next($request);
    }
}
