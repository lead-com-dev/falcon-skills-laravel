<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company and widget secret
    |--------------------------------------------------------------------------
    |
    | "company" is this app's company slug in Falcon Skills. The widget secret
    | is the HMAC-SHA256 secret shared with Falcon Skills: the widget's user
    | context is signed with it, and Falcon forwards that context on every
    | tool call made for a signed-in visitor. Without both, the widget runs
    | for logged-out visitors only and calls that carry a context are refused.
    |
    */

    'company' => env('FALCON_SKILLS_COMPANY'),

    'widget_secret' => env('FALCON_SKILLS_WIDGET_SECRET'),

    'context_max_ttl_seconds' => (int) env('FALCON_SKILLS_CONTEXT_MAX_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Visitor lookup
    |--------------------------------------------------------------------------
    |
    | The Eloquent model a tool call's user_id (or sub) is found on. Null uses
    | the "users" auth provider's model. For anything more (banned users, a
    | different key column) call FalconSkills::resolveUsersUsing().
    |
    */

    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Widget
    |--------------------------------------------------------------------------
    |
    | <x-falcon-skills::widget /> loads the widget with this key. For a
    | signed-in visitor it fetches a fresh signed context from context_path,
    | which runs behind these middleware and reads the user from this guard
    | (null is the default guard).
    |
    */

    'widget' => [
        'key' => env('FALCON_SKILLS_WIDGET_KEY'),
        'script_url' => env('FALCON_SKILLS_WIDGET_URL', 'https://skills.falcon.com/widget/v1/falcon-skills.js'),
        'context_path' => env('FALCON_SKILLS_CONTEXT_PATH', 'falcon-skills/context'),
        'middleware' => ['web'],
        'guard' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools (MCP endpoint)
    |--------------------------------------------------------------------------
    |
    | Falcon Skills calls this app's tools with POST requests to "path". It
    | sends the service token as "Authorization: Bearer <token>"; while the
    | token is empty the endpoint answers 404. Rate limits are calls per
    | minute for each signed-in visitor, and for all logged-out calls
    | together. "tools" lists classes extending FalconSkills\Tool, each
    | resolved from the container. "log_channel" gets one line per call
    | (method, tool, run id, visitor id, duration, never arguments); null
    | logs nothing.
    |
    */

    'mcp' => [
        'path' => env('FALCON_SKILLS_MCP_PATH', 'mcp/falcon'),
        'token' => env('FALCON_SKILLS_MCP_TOKEN'),
        'rate_limit_per_minute' => (int) env('FALCON_SKILLS_MCP_RATE_LIMIT', 120),
        'guest_rate_limit_per_minute' => (int) env('FALCON_SKILLS_MCP_GUEST_RATE_LIMIT', 600),
        'server' => [
            'name' => env('FALCON_SKILLS_MCP_SERVER_NAME', env('APP_NAME', 'Laravel').' for Falcon Skills'),
            'version' => '1.0.0',
            'instructions' => null,
        ],
        'tools' => [],
        'log_channel' => env('FALCON_SKILLS_MCP_LOG_CHANNEL'),
    ],

];
