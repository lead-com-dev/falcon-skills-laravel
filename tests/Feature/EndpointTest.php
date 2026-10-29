<?php

namespace FalconSkills\Tests\Feature;

use FalconSkills\McpServer;
use FalconSkills\Tests\TestCase;
use Illuminate\Support\Str;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class EndpointTest extends TestCase
{
    public function test_endpoint_is_off_without_a_service_token(): void
    {
        config(['falcon-skills.mcp.token' => null]);

        $this->rpc('tools/list')->assertNotFound();
        $this->rpc('tools/list', [], null, null)->assertNotFound();
    }

    public function test_missing_or_wrong_service_token_is_refused_without_an_oauth_challenge(): void
    {
        $this->rpc('tools/list', [], null, null)->assertUnauthorized()->assertHeaderMissing('WWW-Authenticate');
        $this->rpc('tools/list', [], null, 'wrong-token')->assertUnauthorized();
        $this->rpc('tools/list', [], null, Str::random(80))->assertUnauthorized();
    }

    public function test_only_post_is_served(): void
    {
        $this->getJson('/mcp/falcon', $this->headers())->assertMethodNotAllowed();
    }

    public function test_initialize_echoes_a_supported_protocol_version(): void
    {
        $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'falcon-skills', 'version' => '1.0'],
        ])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'Acme for Falcon')
            ->assertJsonPath('result.capabilities.tools.listChanged', false);
    }

    public function test_initialize_answers_with_the_latest_version_for_an_unknown_one(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', McpServer::PROTOCOL_VERSIONS[0]);
    }

    public function test_notifications_get_202_with_no_body(): void
    {
        $response = $this->postJson('/mcp/falcon', [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $this->headers());

        $response->assertStatus(202);
        $this->assertSame('', $response->getContent());
    }

    public function test_ping_returns_an_empty_object(): void
    {
        $response = $this->rpc('ping')->assertOk();

        $this->assertStringContainsString('"result":{}', (string) $response->getContent());
    }

    public function test_lists_the_configured_tools(): void
    {
        $response = $this->rpc('tools/list')->assertOk();
        $tools = collect($response->json('result.tools'))->keyBy('name');

        $this->assertSame(['greet-visitor', 'who-am-i', 'broken-tool'], $tools->keys()->all());
        $this->assertSame('Greet Visitor', $tools['greet-visitor']['title']);
        $this->assertSame(['name'], $tools['greet-visitor']['inputSchema']['required']);
        $this->assertTrue($tools['greet-visitor']['annotations']['readOnlyHint']);
        $this->assertFalse($tools['who-am-i']['annotations']['readOnlyHint']);
        $this->assertStringContainsString('"properties":{}', (string) $response->getContent());
    }

    public function test_unknown_method_and_unknown_tool_are_json_rpc_errors(): void
    {
        $this->rpc('resources/list')->assertOk()->assertJsonPath('error.code', -32601);

        $this->rpc('tools/call', ['name' => 'drop-tables', 'arguments' => (object) []])
            ->assertOk()
            ->assertJsonPath('error.code', -32602)
            ->assertJsonPath('error.message', 'Unknown tool: drop-tables');
    }

    public function test_arguments_must_be_an_object(): void
    {
        $this->rpc('tools/call', ['name' => 'greet-visitor', 'arguments' => ['Ada']])
            ->assertOk()
            ->assertJsonPath('error.code', -32602);
    }

    public function test_malformed_json_and_batches_are_refused(): void
    {
        $this->call('POST', '/mcp/falcon', [], [], [], $this->transportHeaders(), '{not json')
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32700);

        $this->postJson('/mcp/falcon', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ], $this->headers())->assertOk()->assertJsonPath('error.code', -32600);
    }

    #[DefineEnvironment('usesCustomPath')]
    public function test_the_path_can_be_changed(): void
    {
        $this->postJson('/falcon/tools', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $this->headers())
            ->assertOk();
        $this->postJson('/mcp/falcon', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $this->headers())
            ->assertNotFound();
    }

    public function test_signed_in_and_logged_out_calls_are_rate_limited_separately(): void
    {
        config(['falcon-skills.mcp.rate_limit_per_minute' => 2, 'falcon-skills.mcp.guest_rate_limit_per_minute' => 1]);

        $this->rpc('ping')->assertOk();
        $this->rpc('ping')->assertStatus(429);

        $context = $this->contextFor(42);
        $this->rpc('ping', [], $context)->assertOk();
        $this->rpc('ping', [], $context)->assertOk();
        $this->rpc('ping', [], $context)->assertStatus(429);
    }

    /**
     * @return array<string, string>
     */
    private function transportHeaders(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::SERVICE_TOKEN,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }

    protected function usesCustomPath($app): void
    {
        $app['config']->set('falcon-skills.mcp.path', 'falcon/tools');
    }
}
