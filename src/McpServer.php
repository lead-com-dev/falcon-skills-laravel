<?php

namespace FalconSkills;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use InvalidArgumentException;
use Throwable;

/**
 * The slice of MCP that Falcon uses, over plain JSON-RPC: initialize,
 * ping, tools/list and tools/call. No sessions and no streaming, so it does
 * not depend on any MCP library version the app may already have.
 */
final class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public const SIGN_IN_MESSAGE = 'Sign in to use this tool.';

    public const FAILED_MESSAGE = 'This tool could not run right now.';

    /**
     * @var array<string, Tool>|null
     */
    private ?array $resolvedTools = null;

    /**
     * @param  list<class-string<Tool>>  $tools
     * @param  array{name?: string, version?: string, instructions?: string|null}  $server
     */
    public function __construct(
        private Container $container,
        private ValidationFactory $validation,
        private array $tools,
        private array $server,
    ) {
    }

    public static function fromConfig(Container $container): self
    {
        return new self(
            $container,
            $container->make(ValidationFactory::class),
            array_values((array) config('falcon-skills.mcp.tools', [])),
            (array) config('falcon-skills.mcp.server', []),
        );
    }

    /**
     * Handles one decoded JSON-RPC message. Returns null for notifications,
     * which get no response body.
     *
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message, ?Authenticatable $user): ?array
    {
        if (! is_array($message) || array_is_list($message)
            || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error(null, -32600, 'Invalid Request');
        }

        if (! array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];

        if (! is_string($id) && ! is_int($id)) {
            return $this->error(null, -32600, 'Invalid Request');
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($message['method']) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => array_values(array_map(
                fn (Tool $tool): array => $tool->definition(),
                $this->tools(),
            ))]),
            'tools/call' => $this->callTool($id, $params, $user),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    /**
     * @return array<string, Tool>
     */
    public function tools(): array
    {
        if ($this->resolvedTools !== null) {
            return $this->resolvedTools;
        }

        $this->resolvedTools = [];

        foreach ($this->tools as $class) {
            $tool = $this->container->make($class);

            if (! $tool instanceof Tool) {
                throw new InvalidArgumentException("Falcon tool [{$class}] must extend ".Tool::class.'.');
            }

            $this->resolvedTools[$tool->name()] = $tool;
        }

        return $this->resolvedTools;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        $result = [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => (string) ($this->server['name'] ?? 'Falcon tools'),
                'version' => (string) ($this->server['version'] ?? '1.0.0'),
            ],
        ];

        if (filled($this->server['instructions'] ?? null)) {
            $result['instructions'] = (string) $this->server['instructions'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(string|int $id, array $params, ?Authenticatable $user): array
    {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? ($this->tools()[$name] ?? null) : null;

        if ($tool === null) {
            return $this->error($id, -32602, 'Unknown tool: '.(is_string($name) ? $name : ''));
        }

        $arguments = $params['arguments'] ?? [];

        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return $this->error($id, -32602, 'Tool arguments must be an object.');
        }

        return $this->result($id, $this->runTool($tool, $arguments, $user)->toArray());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runTool(Tool $tool, array $arguments, ?Authenticatable $user): ToolResult
    {
        if ($tool->requiresUser() && $user === null) {
            return ToolResult::error(self::SIGN_IN_MESSAGE);
        }

        $validator = $this->validation->make($arguments, $tool->rules());

        if ($validator->fails()) {
            return ToolResult::error((string) $validator->errors()->first());
        }

        $arguments = $validator->validated();

        try {
            return $tool->handle($arguments, $user);
        } catch (Throwable $exception) {
            report($exception);

            return ToolResult::error(self::FAILED_MESSAGE);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string|int $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public function error(string|int|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
