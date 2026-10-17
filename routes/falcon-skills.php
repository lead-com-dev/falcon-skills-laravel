<?php

use FalconSkills\Http\McpController;
use FalconSkills\Http\Middleware\ResolveVisitor;
use FalconSkills\Http\Middleware\VerifyServiceToken;
use FalconSkills\Http\WidgetContextController;
use Illuminate\Support\Facades\Route;

Route::post(config('falcon-skills.mcp.path', 'mcp/falcon'), McpController::class)
    ->middleware([VerifyServiceToken::class, ResolveVisitor::class, 'throttle:falcon-skills-mcp'])
    ->name('falcon-skills.mcp');

Route::get(config('falcon-skills.widget.context_path', 'falcon-skills/context'), WidgetContextController::class)
    ->middleware(config('falcon-skills.widget.middleware', ['web']))
    ->name('falcon-skills.context');
