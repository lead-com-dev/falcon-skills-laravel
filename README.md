# Falcon Skills for Laravel

Puts the Falcon Skills widget on your Laravel app and lets Falcon run your app's tools for its visitors. You add one Blade component to your layout, list your tool classes, and set four environment variables.

- **Widget.** `<x-falcon-skills::widget />` loads the widget. When a visitor is signed in, the widget fetches a short-lived signed token from your app, so Falcon knows who is asking without trusting anything the browser says.
- **Tools.** Falcon calls your tools over MCP at `POST /mcp/falcon` and runs each call as the signed-in visitor.

Works with Laravel 12 and 13 on PHP 8.2+. Laravel 11 is not supported: every Laravel 11 release now has open security advisories, so Composer refuses it. The package has no dependency on `laravel/mcp`, so it sits next to any MCP server the app already runs. For Node apps, see [falcon-skills-node](https://github.com/lead-com-dev/falcon-skills-node).

## Install

Until the package is on Packagist, add this repository to Composer first.

```bash
composer config repositories.falcon-skills vcs https://github.com/lead-com-dev/falcon-skills-laravel
composer require falcon-skills/laravel
php artisan vendor:publish --tag=falcon-skills-config
```

```dotenv
FALCON_SKILLS_COMPANY=       # your company slug in Falcon Skills
FALCON_SKILLS_WIDGET_KEY=    # the widget key (ck_...) from Falcon Skills
FALCON_SKILLS_WIDGET_SECRET= # the widget signing secret from Falcon Skills
FALCON_SKILLS_MCP_TOKEN=     # generated at setup, also entered in Falcon Skills
```

## Add the widget

Put the component in your layout, before `</body>`:

```blade
<x-falcon-skills::widget />
```

- Logged-out visitors get the widget as they are.
- For a signed-in visitor, the component also gives the widget a function that fetches a fresh token from `GET /falcon-skills/context`. No token is ever printed into the page. Each token carries `company`, `user_id`, a new `jti`, and expires in 5 minutes.
- Extra attributes go on the widget's script tag, for example `data-falcon-skills-page="dashboard"`. A CSP nonce is picked up from `Vite::cspNonce()`, or pass `nonce="..."`.
- After a logout that doesn't reload the page (an Inertia or Livewire visit), call `window.FalconSkills?.clearIdentity()`.
- A problem with the widget never breaks your page. If anything fails while the widget renders, the error is reported to your exception handler and the widget renders nothing.

To keep the page up even when the package isn't installed (for example, a deploy that skipped `composer install`), include it instead of using the component tag. `@includeIf` renders nothing when the view doesn't exist:

```blade
@includeIf('falcon-skills::widget')
```

Pass a CSP nonce with `@includeIf('falcon-skills::widget', ['nonce' => $nonce])`.

The token carries the visitor's `name` by default. To send the claims your Falcon Skills workspace expects, set them in a service provider. The package always sets `company`, `user_id`, `jti`, `iat` and `exp` itself.

```php
use FalconSkills\FalconSkills;

FalconSkills::widgetClaimsUsing(fn (User $user): array => [
    'name' => $user->name,
    'account_age' => (int) $user->created_at->diffInDays(),
]);
```

## Write a tool

```php
namespace App\FalconSkills;

use FalconSkills\Tool;
use FalconSkills\ToolResult;
use Illuminate\Contracts\Auth\Authenticatable;

class OrderStatus extends Tool
{
    protected bool $requiresUser = true;

    public function description(): string
    {
        return 'Show the status of one of the visitor\'s orders.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['order' => ['type' => 'string', 'description' => 'Order number']],
            'required' => ['order'],
        ];
    }

    public function rules(): array
    {
        return ['order' => ['required', 'string', 'max:32']];
    }

    public function handle(array $arguments, ?Authenticatable $user): ToolResult
    {
        $order = $user->orders()->where('number', $arguments['order'])->first();

        if ($order === null) {
            return ToolResult::card("You have no order {$arguments['order']}.");
        }

        return ToolResult::card(
            "Order {$order->number} is {$order->status}.",
            [['label' => 'Placed', 'value' => $order->created_at->format('M j, Y')]],
            [['label' => 'Open your orders', 'url' => route('orders.index')]],
        );
    }
}
```

Then list it in `config/falcon-skills.php`:

```php
'mcp' => [
    // ...
    'tools' => [
        App\FalconSkills\OrderStatus::class,
    ],
],
```

In Falcon Skills, add an MCP connection with base URL `https://your-app.com/mcp/falcon`, header `Authorization` and secret `Bearer <FALCON_SKILLS_MCP_TOKEN>`.

- The tool name Falcon sees is the kebab-case class name (`order-status`). Override `name()` to change it.
- `$requiresUser = true` answers "Sign in to use this tool." to logged-out visitors before `handle()` runs.
- `$readOnly` (default `true`) is shown to Falcon as `readOnlyHint`.
- `rules()` are Laravel validation rules. A failure answers with the first message, and arguments without a rule are dropped, so a `user_id` argument never reaches your tool.
- `ToolResult::card()` is what the widget renders. `ToolResult::error()` is a message the visitor sees. An exception is reported and the visitor sees "This tool could not run right now."
- Tools are resolved from the container, so constructor injection works.

## Find the visitor your way

On a tool call, the token's `user_id` (or `sub`) is looked up on the `users` auth provider's model, or on `falcon-skills.user_model`. To skip banned accounts or use another column, set a resolver in a service provider. Returning null refuses the call with 401.

```php
use FalconSkills\FalconSkills;

FalconSkills::resolveUsersUsing(fn (string $userId) => User::query()->where('banned', false)->find($userId));
```

## How a tool call is checked

1. **Service token.** Falcon sends `Authorization: Bearer <token>`. Anything else gets 401, with no `WWW-Authenticate` challenge. Until the token is set, the endpoint answers 404.
2. **User context.** For a signed-in visitor, Falcon forwards the widget's token in `X-Falcon-User-Context`. It is verified with the widget secret. A token that fails any check gets 401 and never runs as a guest. No header means a logged-out visitor. The visitor is kept on the request only, so your auth guards don't change.
3. **Rate limit.** 120 calls a minute per signed-in visitor, and 600 for all logged-out calls together, since those all come from Falcon's servers. Over the limit gets 429.
4. **Tool.** Arguments are validated, and the visitor is passed to your tool.

### The signed user context

The widget gets it from your app, and Falcon forwards it unchanged. Verification accepts either form:

- **Compact** (what this package signs): `base64url(payload) . base64url(HMAC-SHA256(base64url(payload), widget secret))`
- **JWT:** an HS256 JWT signed with the widget secret. Any other `alg` is refused.

| Claim | |
| --- | --- |
| `company` | Your company slug. Must match `FALCON_SKILLS_COMPANY`. |
| `user_id` (or `sub`) | The visitor's id in your app: up to 191 letters, digits or `_ . : @ -`. |
| `exp` | Unix time it expires. At most 5 minutes ahead (60 seconds of clock skew allowed). |
| `jti` | A new one per token. It may repeat across tool calls until the token expires. |

### What Falcon calls

One JSON-RPC 2.0 message per request: `initialize`, `ping`, `tools/list` and `tools/call` (protocol versions 2025-11-25 back to 2024-11-05). Notifications get 202 with no body; batches are refused. A tool answers with text content, plus `structuredContent` for a card:

```json
{
  "summary": "Order 1042 is shipped.",
  "fields": [{ "label": "Placed", "value": "Oct 14, 2026" }],
  "links": [{ "label": "Open your orders", "url": "https://your-app.com/orders" }]
}
```

## Other settings

See `config/falcon-skills.php`: the widget script URL, context path, middleware and guard; the MCP path, rate limits, server name and instructions; and a log channel for one line per tool call with method, tool, `X-Falcon-Run-Id`, visitor id and duration, never arguments.

## Tests

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
