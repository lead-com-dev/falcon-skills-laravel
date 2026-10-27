<?php

namespace FalconSkills\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyServiceToken
{
    /**
     * Only Falcon's servers hold the service token. With no token
     * configured the endpoint is off and answers 404 to every caller. The 401
     * carries no WWW-Authenticate challenge, so OAuth clients don't try to
     * sign in here.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('falcon-skills.mcp.token');

        abort_if($expected === '', 404);

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
