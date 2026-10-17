<?php

namespace FalconSkills;

use Closure;
use FalconSkills\Http\Middleware\ResolveVisitor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class FalconSkills
{
    /**
     * @var (Closure(string, array<string, mixed>): ?Authenticatable)|null
     */
    private static ?Closure $userResolver = null;

    /**
     * @var (Closure(Authenticatable): array<string, mixed>)|null
     */
    private static ?Closure $widgetClaims = null;

    /**
     * Decide how a verified user context becomes a user, for example to skip
     * banned accounts. Return null to refuse the call with 401.
     *
     * @param  (Closure(string $userId, array<string, mixed> $claims): ?Authenticatable)|null  $resolver
     */
    public static function resolveUsersUsing(?Closure $resolver): void
    {
        self::$userResolver = $resolver;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function resolveUser(string $userId, array $claims): ?Authenticatable
    {
        if (self::$userResolver !== null) {
            return (self::$userResolver)($userId, $claims);
        }

        /** @var class-string<Model>|null $model */
        $model = config('falcon-skills.user_model') ?? config('auth.providers.users.model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        $user = $model::query()->find($userId);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Set the claims the widget's signed context carries for a visitor, such
     * as name or account age. company, user_id, jti, iat and exp are always
     * set by the package and win over anything returned here.
     *
     * @param  (Closure(Authenticatable $user): array<string, mixed>)|null  $callback
     */
    public static function widgetClaimsUsing(?Closure $callback): void
    {
        self::$widgetClaims = $callback;
    }

    /**
     * The app's claims for a visitor. Without a callback: the user's name.
     *
     * @return array<string, mixed>
     */
    public static function widgetClaims(Authenticatable $user): array
    {
        if (self::$widgetClaims !== null) {
            return (self::$widgetClaims)($user);
        }

        $name = data_get($user, 'name');

        return is_string($name) && $name !== '' ? ['name' => $name] : [];
    }

    /**
     * The signed-in widget visitor of a Falcon call, or null when logged out.
     */
    public static function visitor(Request $request): ?Authenticatable
    {
        $visitor = $request->attributes->get(ResolveVisitor::ATTRIBUTE);

        return $visitor instanceof Authenticatable ? $visitor : null;
    }
}
