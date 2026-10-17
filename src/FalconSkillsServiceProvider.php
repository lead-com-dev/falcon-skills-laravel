<?php

namespace FalconSkills;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class FalconSkillsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/falcon-skills.php', 'falcon-skills');

        $this->app->bind(McpServer::class, fn ($app): McpServer => McpServer::fromConfig($app));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/falcon-skills.php' => config_path('falcon-skills.php'),
        ], 'falcon-skills-config');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'falcon-skills');

        Blade::componentNamespace('FalconSkills\\View\\Components', 'falcon-skills');

        RateLimiter::for('falcon-skills-mcp', function (Request $request): Limit {
            $visitor = FalconSkills::visitor($request);

            if ($visitor === null) {
                return Limit::perMinute(max(1, (int) config('falcon-skills.mcp.guest_rate_limit_per_minute', 600)))
                    ->by('falcon-skills-guests');
            }

            return Limit::perMinute(max(1, (int) config('falcon-skills.mcp.rate_limit_per_minute', 120)))
                ->by('falcon-skills-user:'.$visitor->getAuthIdentifier());
        });

        $this->loadRoutesFrom(__DIR__.'/../routes/falcon-skills.php');
    }
}
