<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Tests\TestCase;

final class SettingsEndpointTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.settings.store', 'database');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../../database/migrations/create_ai_guardrails_settings_table.php.stub';
        $migration->up();
    }

    public function test_show_returns_nested_effective_settings(): void
    {
        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.settings')
            ->assertJsonPath('data.settings.input_screen.enabled', true)
            ->assertJsonPath('data.settings.hitl.fallback', 'deny');
    }

    public function test_update_persists_an_allow_listed_override(): void
    {
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['input_screen.enabled' => false]])
            ->assertOk()
            ->assertJsonPath('data.settings.input_screen.enabled', false);

        // A subsequent GET reflects the persisted override.
        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.input_screen.enabled', false);
    }

    public function test_update_coerces_string_booleans(): void
    {
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['output_handler.enabled' => 'false']])
            ->assertOk()
            ->assertJsonPath('data.settings.output_handler.enabled', false);
    }

    public function test_non_overridable_keys_are_ignored_not_persisted(): void
    {
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['audit.store' => 'database', 'input_screen.enabled' => false]])
            ->assertOk()
            ->assertJsonPath('data.settings.input_screen.enabled', false);

        // The non-overridable key was dropped, not persisted.
        self::assertSame(0, DB::table('ai_guardrails_settings')->where('key', 'audit.store')->count());
    }

    public function test_invalid_enum_value_is_rejected_with_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['hitl.fallback' => 'explode']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.hitl.fallback');
    }

    public function test_invalid_boolean_value_is_rejected_with_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['input_screen.enabled' => 'maybe']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.input_screen.enabled');
    }

    public function test_missing_settings_body_is_rejected(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [])->assertStatus(422);
    }

    public function test_over_length_string_value_is_rejected_with_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['input_screen.refusal_message' => str_repeat('x', 3000)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.input_screen.refusal_message');
    }
}
