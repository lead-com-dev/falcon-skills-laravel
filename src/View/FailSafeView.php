<?php

namespace FalconSkills\View;

use Closure;
use Illuminate\Contracts\View\View;

/**
 * Wraps a view so that anything going wrong while building or rendering it
 * is reported and renders nothing. A problem with the widget must never
 * break the host page.
 */
class FailSafeView implements View
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param  Closure(): View  $view
     */
    public function __construct(private string $name, private Closure $view)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @param  string|array<string, mixed>  $key
     */
    public function with($key, $value = null): static
    {
        $this->data = array_merge($this->data, is_array($key) ? $key : [$key => $value]);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function render(): string
    {
        return rescue(fn (): string => ($this->view)()->with($this->data)->render(), '');
    }
}
