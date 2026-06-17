<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * store=database but the settings table is NOT migrated: the write path must fail safe (503), not 500.
 */
final class SettingsWriteFailureTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.settings.store', 'database');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        // Intentionally do NOT migrate the settings table.
    }

    public function test_persist_failure_returns_503_not_500(): void
    {
        $this->putJson('/ai-guardrails/api/settings', ['settings' => ['input_screen.enabled' => false]])
            ->assertStatus(503)
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.settings')
            ->assertJsonPath('data.error', 'persist_failed');
    }

    public function test_read_path_still_fails_safe_to_defaults(): void
    {
        // GET must not 500 either — it returns the file defaults.
        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.input_screen.enabled', true);
    }
}
