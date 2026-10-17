<?php

namespace FalconSkills\Tests\Fixtures;

use FalconSkills\Tool;
use FalconSkills\ToolResult;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

class BrokenTool extends Tool
{
    public function description(): string
    {
        return 'Always fails.';
    }

    public function handle(array $arguments, ?Authenticatable $user): ToolResult
    {
        throw new RuntimeException('SQLSTATE[HY000]: secret connection details');
    }
}
