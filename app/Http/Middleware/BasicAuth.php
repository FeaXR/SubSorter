<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional HTTP basic auth for the whole UI (enabled when BASIC_AUTH_USER is set).
 */
class BasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = config('youtube.basic_auth.user');
        $password = (string) config('youtube.basic_auth.password');

        if (blank($user)) {
            return $next($request);
        }

        if (hash_equals($user, (string) $request->getUser()) && hash_equals($password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="SubSorter"']);
    }
}
