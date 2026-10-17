<?php

namespace FalconSkills;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * The signed user context: the token the app signs for its widget with the
 * shared widget secret, which Falcon Skills forwards on each tool call
 * (X-Falcon-User-Context). Verification takes the compact 2-part form or an
 * HS256 JWT. A jti may repeat: one token covers several calls until it
 * expires.
 */
final class UserContext
{
    public const CLOCK_SKEW_SECONDS = 60;

    /**
     * @return array{user_id: string, claims: array<string, mixed>}
     *
     * @throws InvalidUserContextException
     */
    public function verify(string $token, ?int $now = null): array
    {
        [$secret, $company] = $this->settings();

        $parts = explode('.', trim($token));

        if (count($parts) === 2) {
            [$encodedPayload, $signature] = $parts;
            $signingInput = $encodedPayload;
        } elseif (count($parts) === 3) {
            [$encodedHeader, $encodedPayload, $signature] = $parts;
            $header = json_decode((string) $this->base64UrlDecode($encodedHeader), true);

            if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
                throw new InvalidUserContextException('Unsupported user context algorithm.');
            }

            $signingInput = $encodedHeader.'.'.$encodedPayload;
        } else {
            throw new InvalidUserContextException('Malformed user context.');
        }

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidUserContextException('Invalid user context signature.');
        }

        $claims = json_decode((string) $this->base64UrlDecode($encodedPayload), true);

        if (! is_array($claims)) {
            throw new InvalidUserContextException('Invalid user context payload.');
        }

        if (($claims['company'] ?? null) !== $company) {
            throw new InvalidUserContextException('User context is for another company.');
        }

        $currentTime = $now ?? time();
        $expiresAt = $claims['exp'] ?? null;

        if (! is_int($expiresAt) && ! (is_string($expiresAt) && ctype_digit($expiresAt))) {
            throw new InvalidUserContextException('User context has no expiry.');
        }

        $expiresAt = (int) $expiresAt;

        if ($expiresAt < $currentTime - self::CLOCK_SKEW_SECONDS) {
            throw new InvalidUserContextException('User context has expired.');
        }

        if ($expiresAt > $currentTime + $this->maxTtl() + self::CLOCK_SKEW_SECONDS) {
            throw new InvalidUserContextException('User context expires too far ahead.');
        }

        $userId = $claims['user_id'] ?? $claims['sub'] ?? null;

        if (is_int($userId)) {
            $userId = (string) $userId;
        }

        if (! is_string($userId) || preg_match('/^[A-Za-z0-9_.:@-]{1,191}$/', $userId) !== 1) {
            throw new InvalidUserContextException('User context has no valid user_id.');
        }

        return [
            'user_id' => $userId,
            'claims' => $claims,
        ];
    }

    /**
     * A fresh compact token for the widget: the app's claims for the visitor
     * plus company, user_id, a new jti, iat, and exp at the longest TTL.
     *
     * @throws InvalidUserContextException
     */
    public function sign(Authenticatable $user, ?int $now = null): string
    {
        [$secret, $company] = $this->settings();
        $issuedAt = $now ?? time();

        $claims = array_merge(FalconSkills::widgetClaims($user), [
            'company' => $company,
            'user_id' => (string) $user->getAuthIdentifier(),
            'jti' => (string) Str::uuid(),
            'iat' => $issuedAt,
            'exp' => $issuedAt + $this->maxTtl(),
        ]);

        $payload = $this->base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->base64UrlEncode(hash_hmac('sha256', $payload, $secret, true));
    }

    public function isConfigured(): bool
    {
        return (string) config('falcon-skills.widget_secret') !== '' && (string) config('falcon-skills.company') !== '';
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws InvalidUserContextException
     */
    private function settings(): array
    {
        if (! $this->isConfigured()) {
            throw new InvalidUserContextException('User context signing is not configured.');
        }

        return [(string) config('falcon-skills.widget_secret'), (string) config('falcon-skills.company')];
    }

    private function maxTtl(): int
    {
        return max(60, (int) config('falcon-skills.context_max_ttl_seconds', 300));
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
