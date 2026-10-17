<?php

namespace FalconSkills\Tests\Feature;

use FalconSkills\FalconSkills;
use FalconSkills\Tests\TestCase;
use FalconSkills\UserContext;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\View;
use Illuminate\View\ViewException;
use InvalidArgumentException;

class WidgetTest extends TestCase
{
    protected function tearDown(): void
    {
        FalconSkills::widgetClaimsUsing(null);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('falcon-skills.widget.key', 'ck_test_123');
    }

    public function test_the_context_route_signs_a_fresh_token_for_the_signed_in_visitor(): void
    {
        $this->actingAs($this->ada());

        $first = $this->getJson('/falcon-skills/context')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('token');
        $second = $this->getJson('/falcon-skills/context')->json('token');

        $verified = app(UserContext::class)->verify($first);

        $this->assertSame('42', $verified['user_id']);
        $this->assertSame('acme', $verified['claims']['company']);
        $this->assertSame('Ada', $verified['claims']['name']);
        $this->assertEqualsWithDelta(time() + 300, $verified['claims']['exp'], 2);
        $this->assertNotSame($verified['claims']['jti'], app(UserContext::class)->verify($second)['claims']['jti']);
    }

    public function test_the_context_route_refuses_guests_without_redirecting(): void
    {
        $this->getJson('/falcon-skills/context')
            ->assertUnauthorized()
            ->assertExactJson(['token' => null]);
    }

    public function test_the_context_route_is_off_until_the_secret_and_company_are_set(): void
    {
        config(['falcon-skills.widget_secret' => null]);

        $this->actingAs($this->ada())->getJson('/falcon-skills/context')->assertNotFound();
    }

    public function test_the_app_sets_the_claims_but_not_the_identity_ones(): void
    {
        FalconSkills::widgetClaimsUsing(fn (Authenticatable $user): array => [
            'name' => 'Ada Lovelace',
            'account_age' => 412,
            'has_domain_loan' => false,
            'user_id' => '1',
            'company' => 'someone-else',
        ]);

        $token = $this->actingAs($this->ada())->getJson('/falcon-skills/context')->json('token');
        $claims = app(UserContext::class)->verify($token)['claims'];

        $this->assertSame('Ada Lovelace', $claims['name']);
        $this->assertSame(412, $claims['account_age']);
        $this->assertFalse($claims['has_domain_loan']);
        $this->assertSame('42', $claims['user_id']);
        $this->assertSame('acme', $claims['company']);
    }

    public function test_the_widget_loads_for_logged_out_visitors_without_an_identity(): void
    {
        $view = $this->blade('<x-falcon-skills::widget />');

        $view->assertSee('src="https://skills.falcon.com/widget/v1/falcon-skills.js"', false);
        $view->assertSee('data-falcon-skills-key="ck_test_123"', false);
        $view->assertSee('data-falcon-skills-company="acme"', false);
        $view->assertDontSee('__falconSkillsPendingIdentityProvider', false);
    }

    public function test_the_widget_identifies_signed_in_visitors_through_the_context_route(): void
    {
        $this->actingAs($this->ada());

        $view = $this->blade('<x-falcon-skills::widget data-falcon-skills-page="dashboard" nonce="abc123" />');

        $view->assertSee('window.__falconSkillsPendingIdentityProvider = async function', false);
        $view->assertSee("fetch('\\/falcon-skills\\/context'", false);
        $view->assertSee('window.FalconSkills?.identify(window.__falconSkillsPendingIdentityProvider);', false);
        $view->assertSee('data-falcon-skills-page="dashboard"', false);
        $view->assertSee('nonce="abc123"', false);
        $view->assertDontSee('eyJ', false);
    }

    public function test_the_widget_renders_nothing_without_a_key(): void
    {
        config(['falcon-skills.widget.key' => null]);

        $this->assertSame('', trim((string) $this->blade('<x-falcon-skills::widget />')));
    }

    public function test_the_widget_can_be_included_and_takes_a_nonce(): void
    {
        $view = $this->blade("@includeIf('falcon-skills::widget', ['nonce' => 'abc123'])");

        $view->assertSee('src="https://skills.falcon.com/widget/v1/falcon-skills.js"', false);
        $view->assertSee('nonce="abc123"', false);
    }

    public function test_a_widget_error_is_reported_and_the_page_still_renders(): void
    {
        Exceptions::fake();
        config(['falcon-skills.widget.guard' => 'not-a-guard']);

        $view = $this->blade('<p>before</p><x-falcon-skills::widget /><p>after</p>');

        $view->assertSee('<p>before</p>', false);
        $view->assertSee('<p>after</p>', false);
        $view->assertDontSee('falcon-skills.js', false);
        Exceptions::assertReported(InvalidArgumentException::class);
    }

    public function test_a_broken_widget_view_renders_nothing(): void
    {
        Exceptions::fake();
        $directory = sys_get_temp_dir().'/falcon-skills-broken-'.uniqid();
        mkdir($directory.'/components', recursive: true);
        file_put_contents($directory.'/components/widget.blade.php', "@php throw new RuntimeException('broken widget view'); @endphp");
        View::prependNamespace('falcon-skills', $directory);

        $view = $this->blade("<p>before</p>@includeIf('falcon-skills::widget')<p>after</p>");

        $view->assertSee('<p>before</p><p>after</p>', false);
        Exceptions::assertReported(fn (ViewException $exception): bool => str_contains($exception->getMessage(), 'broken widget view'));
    }

    private function ada(): GenericUser
    {
        return new GenericUser(['id' => 42, 'name' => 'Ada', 'remember_token' => null]);
    }
}
