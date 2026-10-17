<?php

namespace FalconSkills\Http;

use FalconSkills\FalconSkills;
use FalconSkills\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use JsonException;

class McpController
{
    public function __invoke(Request $request, McpServer $server): JsonResponse|Response
    {
        $startedAt = hrtime(true);

        try {
            $message = json_decode((string) $request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json($server->error(null, -32700, 'Parse error'), 400);
        }

        $visitor = FalconSkills::visitor($request);
        $response = $server->handle($message, $visitor);

        $this->log($request, $message, $response, $startedAt);

        if ($response === null) {
            return response()->noContent(202);
        }

        return response()->json($response, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    private function log(Request $request, mixed $message, ?array $response, int $startedAt): void
    {
        $channel = config('falcon-skills.mcp.log_channel');

        if (! is_string($channel) || $channel === '') {
            return;
        }

        Log::channel($channel)->info('Falcon MCP call', [
            'method' => is_array($message) ? ($message['method'] ?? null) : null,
            'tool' => is_array($message) ? ($message['params']['name'] ?? null) : null,
            'run_id' => $request->header('X-Falcon-Run-Id'),
            'visitor_id' => FalconSkills::visitor($request)?->getAuthIdentifier(),
            'is_error' => (bool) (($response['result']['isError'] ?? false) || isset($response['error'])),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
