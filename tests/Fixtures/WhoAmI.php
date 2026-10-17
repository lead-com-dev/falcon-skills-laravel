<?php

namespace FalconSkills\Tests\Fixtures;

use FalconSkills\Tool;
use FalconSkills\ToolResult;
use Illuminate\Contracts\Auth\Authenticatable;

class WhoAmI extends Tool
{
    /**
     * @var array<string, mixed>|null
     */
    public static ?array $lastArguments = null;

    protected bool $requiresUser = true;

    protected bool $readOnly = false;

    public function description(): string
    {
        return 'Say which account the call runs as.';
    }

    public function handle(array $arguments, ?Authenticatable $user): ToolResult
    {
        self::$lastArguments = $arguments;

        return ToolResult::text('You are user '.$user?->getAuthIdentifier().'.');
    }
}
