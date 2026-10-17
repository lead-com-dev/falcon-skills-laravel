<?php

namespace FalconSkills\Tests\Feature;

use FalconSkills\FalconSkills;
use FalconSkills\Tests\Fixtures\User;
use FalconSkills\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DefaultUserLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FalconSkills::resolveUsersUsing(null);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        User::query()->create(['id' => 42, 'name' => 'Ada']);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('falcon-skills.user_model', User::class);
    }

    public function test_the_visitor_is_found_on_the_configured_model(): void
    {
        $this->assertSame('You are user 42.', $this->callTool('who-am-i', [], $this->contextFor(42))['text']);
    }

    public function test_a_user_missing_from_the_model_is_refused(): void
    {
        $this->rpc('tools/call', ['name' => 'who-am-i', 'arguments' => (object) []], $this->contextFor(7))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unknown user.');
    }
}
