<?php

namespace FalconSkills\Tests\Fixtures;

use FalconSkills\Tool;
use FalconSkills\ToolResult;
use Illuminate\Contracts\Auth\Authenticatable;

class GreetVisitor extends Tool
{
    /**
     * @var array<string, mixed>|null
     */
    public static ?array $lastArguments = null;

    public function description(): string
    {
        return 'Greet the visitor by the name they give.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'description' => 'Who to greet']],
            'required' => ['name'],
        ];
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:40']];
    }

    public function handle(array $arguments, ?Authenticatable $user): ToolResult
    {
        self::$lastArguments = $arguments;

        return ToolResult::card(
            "Hello, {$arguments['name']}!",
            [['label' => 'Signed in', 'value' => $user === null ? 'No' : 'Yes']],
            [['label' => 'Docs', 'url' => 'https://example.com/docs']],
        );
    }
}
