<?php

namespace FalconSkills;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * A tool Falcon Skills can run for a widget visitor. The visitor comes only
 * from the verified user context, never from the arguments.
 */
abstract class Tool
{
    /**
     * Refuse logged-out visitors with "Sign in to use this tool." before
     * handle() runs. Turn on for anything that reads account data.
     */
    protected bool $requiresUser = false;

    /**
     * Advertised to Falcon as readOnlyHint.
     */
    protected bool $readOnly = true;

    abstract public function description(): string;

    /**
     * @param  array<string, mixed>  $arguments  Only the keys rules() names, once they pass.
     */
    abstract public function handle(array $arguments, ?Authenticatable $user): ToolResult;

    /**
     * The name Falcon calls the tool by. Defaults to the kebab-case class name.
     */
    public function name(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    public function title(): string
    {
        return Str::headline(class_basename(static::class));
    }

    /**
     * JSON Schema for the arguments, shown to Falcon in tools/list.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    /**
     * Laravel validation rules for the arguments. A failure returns the first
     * message as an error result. Arguments without a rule are dropped.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function requiresUser(): bool
    {
        return $this->requiresUser;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $schema = $this->inputSchema();

        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = (object) [];
        }

        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $schema,
            'annotations' => [
                'title' => $this->title(),
                'readOnlyHint' => $this->isReadOnly(),
            ],
        ];
    }
}
