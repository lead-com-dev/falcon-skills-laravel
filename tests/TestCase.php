<?php

namespace FalconSkills\Tests;

use FalconSkills\FalconSkills;
use FalconSkills\FalconSkillsServiceProvider;
use FalconSkills\Tests\Fixtures\BrokenTool;
use FalconSkills\Tests\Fixtures\GreetVisitor;
use FalconSkills\Tests\Fixtures\WhoAmI;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const SERVICE_TOKEN = 'falcon-service-token';

    protected const WIDGET_SECRET = 'falcon-widget-secret';

    protected function setUp(): void
    {
        parent::setUp();

        FalconSkills::resolveUsersUsing(fn (string $userId): ?GenericUser => match ($userId) {
            '42' => new GenericUser(['id' => 42, 'name' => 'Ada']),
            'usr_01HZY' => new GenericUser(['id' => 'usr_01HZY', 'name' => 'Grace']),
            default => null,
        });
    }

    protected function tearDown(): void
    {
        FalconSkills::resolveUsersUsing(null);

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [FalconSkillsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('falcon-skills.mcp.token', self::SERVICE_TOKEN);
        $app['config']->set('falcon-skills.widget_secret', self::WIDGET_SECRET);
        $app['config']->set('falcon-skills.company', 'acme');
        $app['config']->set('falcon-skills.mcp.server.name', 'Acme for Falcon Skills');
        $app['config']->set('falcon-skills.mcp.tools', [GreetVisitor::class, WhoAmI::class, BrokenTool::class]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function contextFor(string|int $userId, array $overrides = [], ?string $secret = null): string
    {
        $claims = array_merge([
            'company' => 'acme',
            'jti' => (string) Str::uuid(),
            'user_id' => (string) $userId,
            'exp' => time() + 300,
        ], $overrides);

        $payload = $this->base64Url((string) json_encode($claims));

        return $payload.'.'.$this->base64Url(hash_hmac('sha256', $payload, $secret ?? self::WIDGET_SECRET, true));
    }

    /**
     * @param  array<string, mixed>  $header
     */
    protected function jwtContextFor(string|int $subject, array $header = ['alg' => 'HS256', 'typ' => 'JWT']): string
    {
        $encodedHeader = $this->base64Url((string) json_encode($header));
        $body = $this->base64Url((string) json_encode([
            'company' => 'acme',
            'jti' => (string) Str::uuid(),
            'sub' => $subject,
            'exp' => time() + 300,
        ]));

        return $encodedHeader.'.'.$body.'.'.$this->base64Url(hash_hmac('sha256', $encodedHeader.'.'.$body, self::WIDGET_SECRET, true));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function rpc(string $method, array $params = [], ?string $context = null, ?string $token = self::SERVICE_TOKEN): TestResponse
    {
        return $this->postJson('/mcp/falcon', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], $this->headers($context, $token));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{isError: bool, text: string, structured: array<string, mixed>|null}
     */
    protected function callTool(string $name, array $arguments = [], ?string $context = null): array
    {
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $context)
            ->assertOk()
            ->json('result');

        return [
            'isError' => (bool) $result['isError'],
            'text' => (string) $result['content'][0]['text'],
            'structured' => $result['structuredContent'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function headers(?string $context = null, ?string $token = self::SERVICE_TOKEN): array
    {
        $headers = [];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($context !== null) {
            $headers['X-Falcon-User-Context'] = $context;
        }

        return $headers;
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
