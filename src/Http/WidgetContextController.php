<?php

namespace FalconSkills\Http;

use FalconSkills\UserContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetContextController
{
    /**
     * Hands the widget a fresh signed context for the signed-in visitor. The
     * widget asks whenever it needs one, so no token is printed into the page.
     * Off (404) until the widget secret and company are set.
     */
    public function __invoke(Request $request, UserContext $context): JsonResponse
    {
        abort_unless($context->isConfigured(), 404);

        $user = $request->user(config('falcon-skills.widget.guard'));

        if (! $user instanceof Authenticatable) {
            return response()->json(['token' => null], 401)->header('Cache-Control', 'no-store');
        }

        return response()->json(['token' => $context->sign($user)])->header('Cache-Control', 'no-store');
    }
}
