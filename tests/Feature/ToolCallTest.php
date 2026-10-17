<?php

namespace FalconSkills\Tests\Feature;

use FalconSkills\McpServer;
use FalconSkills\Tests\Fixtures\GreetVisitor;
use FalconSkills\Tests\Fixtures\WhoAmI;
use FalconSkills\Tests\TestCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ToolCallTest extends TestCase
{
    protected function tearDown(): void
    {
        GreetVisitor::$lastArguments = null;
        WhoAmI::$lastArguments = null;

        parent::tearDown();
    }

    public function test_a_card_comes_back_as_structured_content_and_json_text(): void
    {
        $result = $this->callTool('greet-visitor', ['name' => 'Ada']);

        $this->assertFalse($result['isError']);
        $this->assertSame([
            'summary' => 'Hello, Ada!',
            'fields' => [['label' => 'Signed in', 'value' => 'No']],
            'links' => [['label' => 'Docs', 'url' => 'https://example.com/docs']],
        ], $result['structured']);
        $this->assertSame($result['structured'], json_decode($result['text'], true));
    }

    public function test_failed_rules_return_the_first_message_without_running_the_tool(): void
    {
        $result = $this->callTool('greet-visitor', []);

        $this->assertTrue($result['isError']);
        $this->assertSame('The name field is required.', $result['text']);
        $this->assertNull(GreetVisitor::$lastArguments);
    }

    public function test_arguments_without_a_rule_never_reach_the_tool(): void
    {
        $this->callTool('greet-visitor', ['name' => 'Ada', 'user_id' => 7]);

        $this->assertSame(['name' => 'Ada'], GreetVisitor::$lastArguments);
    }

    public function test_a_tool_without_rules_gets_no_arguments(): void
    {
        $this->callTool('who-am-i', ['user_id' => 7, 'note' => 'hi'], $this->contextFor(42));

        $this->assertSame([], WhoAmI::$lastArguments);
    }

    public function test_a_tool_that_needs_a_user_asks_logged_out_visitors_to_sign_in(): void
    {
        $result = $this->callTool('who-am-i');

        $this->assertTrue($result['isError']);
        $this->assertSame(McpServer::SIGN_IN_MESSAGE, $result['text']);
    }

    public function test_a_user_id_in_the_arguments_does_not_sign_anyone_in(): void
    {
        $result = $this->callTool('who-am-i', ['user_id' => 42]);

        $this->assertSame(McpServer::SIGN_IN_MESSAGE, $result['text']);
    }

    public function test_the_tool_runs_as_the_visitor_in_the_signed_context(): void
    {
        $result = $this->callTool('who-am-i', [], $this->contextFor(42));

        $this->assertFalse($result['isError']);
        $this->assertSame('You are user 42.', $result['text']);
    }

    public function test_the_visitor_does_not_leak_into_the_apps_auth_guard(): void
    {
        $this->callTool('who-am-i', [], $this->contextFor(42));

        $this->assertNull(auth()->user());
    }

    public function test_a_jwt_context_with_sub_and_non_numeric_ids_are_accepted(): void
    {
        $this->assertSame('You are user 42.', $this->callTool('who-am-i', [], $this->jwtContextFor(42))['text']);
        $this->assertSame('You are user usr_01HZY.', $this->callTool('who-am-i', [], $this->contextFor('usr_01HZY'))['text']);
    }

    public function test_the_same_context_works_for_several_calls(): void
    {
        $context = $this->contextFor(42);

        $this->assertSame('You are user 42.', $this->callTool('who-am-i', [], $context)['text']);
        $this->assertSame('You are user 42.', $this->callTool('who-am-i', [], $context)['text']);
    }

    public function test_bad_contexts_are_refused_instead_of_running_as_a_guest(): void
    {
        $badContexts = [
            'wrong secret' => $this->contextFor(42, [], 'another-secret'),
            'expired' => $this->contextFor(42, ['exp' => time() - 120]),
            'too long lived' => $this->contextFor(42, ['exp' => time() + 3600]),
            'another company' => $this->contextFor(42, ['company' => 'someone-else']),
            'no expiry' => $this->contextFor(42, ['exp' => null]),
            'unknown user' => $this->contextFor(999),
            'odd user id' => $this->contextFor('42 OR 1=1'),
            'unsigned jwt' => $this->jwtContextFor(42, ['alg' => 'none', 'typ' => 'JWT']),
            'malformed' => 'not-a-token',
        ];

        foreach ($badContexts as $case => $context) {
            $this->rpc('tools/call', [
                'name' => 'greet-visitor',
                'arguments' => ['name' => 'Ada'],
            ], $context)->assertUnauthorized($case);
        }
    }

    public function test_contexts_are_refused_until_secret_and_company_are_configured(): void
    {
        config(['falcon-skills.company' => null]);
        $this->rpc('tools/list', [], $this->contextFor(42))->assertUnauthorized();

        config(['falcon-skills.company' => 'acme', 'falcon-skills.widget_secret' => null]);
        $this->rpc('tools/list', [], $this->contextFor(42))->assertUnauthorized();

        $this->rpc('tools/list')->assertOk();
    }

    public function test_a_failing_tool_reports_the_exception_and_shows_a_plain_message(): void
    {
        Exceptions::fake();

        $result = $this->callTool('broken-tool');

        $this->assertTrue($result['isError']);
        $this->assertSame(McpServer::FAILED_MESSAGE, $result['text']);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_calls_are_logged_without_arguments_when_a_channel_is_set(): void
    {
        config(['falcon-skills.mcp.log_channel' => 'falcon-test']);

        Log::shouldReceive('channel')->once()->with('falcon-test')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            return $context['tool'] === 'greet-visitor'
                && $context['run_id'] === 'run_8f3k2'
                && $context['visitor_id'] === 42
                && ! str_contains((string) json_encode($context), 'Secret Name');
        });

        $this->postJson('/mcp/falcon', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'greet-visitor', 'arguments' => ['name' => 'Secret Name']],
        ], $this->headers($this->contextFor(42)) + ['X-Falcon-Run-Id' => 'run_8f3k2'])->assertOk();
    }
}
