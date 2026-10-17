<?php

namespace FalconSkills\Http\Middleware;

use Closure;
use FalconSkills\FalconSkills;
use FalconSkills\InvalidUserContextException;
use FalconSkills\UserContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveVisitor
{
    public const HEADER = 'X-Falcon-User-Context';

    /**
     * Request attribute the visitor is kept in. The app's auth guards are
     * left alone, so nothing else in the request sees a logged-in user.
     */
    public const ATTRIBUTE = 'falcon_skills_visitor';

    public function __construct(private UserContext $context)
    {
    }

    /**
     * No header is a logged-out widget visitor. A header that does not verify,
     * or names a user the app does not resolve, is refused rather than run as
     * a guest.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) $request->header(self::HEADER, ''));

        if ($token === '') {
            return $next($request);
        }

        try {
            $verified = $this->context->verify($token);
        } catch (InvalidUserContextException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        $user = FalconSkills::resolveUser($verified['user_id'], $verified['claims']);

        if ($user === null) {
            return response()->json(['message' => 'Unknown user.'], 401);
        }

        $request->attributes->set(self::ATTRIBUTE, $user);

        return $next($request);
    }
}
