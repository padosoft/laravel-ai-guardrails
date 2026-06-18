<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Padosoft\AiGuardrails\Events\SettingsChanged;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * E6 — PUT /settings appends a per-key record to the immutable settings-change audit (WHO/WHAT) and
 * dispatches a SettingsChanged event. The actor is derived server-side. GET /settings/changes lists them.
 */
final class SettingsChangeAuditTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.settings.store', 'database');
        $app['config']->set('ai-guardrails.settings_audit.store', 'database');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../../database/migrations/create_ai_guardrails_settings_table.php.stub')->up();
        (require __DIR__.'/../../../database/migrations/create_ai_guardrails_settings_changes_table.php.stub')->up();
    }

    public function test_put_records_the_effective_change_and_lists_it(): void
    {
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['input_screen.enabled' => false]])
            ->assertOk();

        $this->getJson('/ai-guardrails/api/settings/changes')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.settings-changes')
            ->assertJsonPath('data.changes.0.key', 'input_screen.enabled')
            ->assertJsonPath('data.changes.0.old_value', true)
            ->assertJsonPath('data.changes.0.new_value', false);
    }

    public function test_actor_is_derived_server_side(): void
    {
        $user = new class implements Authenticatable
        {
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 99;
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): string
            {
                return '';
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return '';
            }
        };

        $this->actingAs($user)
            ->putJson('/ai-guardrails/api/settings', ['settings' => ['hitl.fallback' => 'pass']])
            ->assertOk();

        $this->getJson('/ai-guardrails/api/settings/changes')
            ->assertOk()
            ->assertJsonPath('data.changes.0.actor_id', '99');
    }

    public function test_no_op_write_records_nothing(): void
    {
        // input_screen.enabled defaults to true → setting it true again is not an effective change.
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['input_screen.enabled' => true]])
            ->assertOk();

        $this->getJson('/ai-guardrails/api/settings/changes')
            ->assertOk()
            ->assertJsonCount(0, 'data.changes');
    }

    public function test_dispatches_a_settings_changed_event(): void
    {
        Event::fake();

        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['output_handler.enabled' => false]])
            ->assertOk();

        Event::assertDispatched(SettingsChanged::class, static function (SettingsChanged $e): bool {
            return count($e->changes) === 1
                && $e->changes[0]->key === 'output_handler.enabled'
                && $e->changes[0]->newValue === false;
        });
    }
}
