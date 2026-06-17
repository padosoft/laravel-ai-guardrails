<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Screening\NullInjectionScreener;
use Padosoft\AiGuardrails\Screening\PatternInjectionScreener;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Proves persisted (database-store) settings are actually applied to the runtime controls — not just
 * reported by the settings API — because the provider overlays them onto config before wiring.
 */
final class SettingsRuntimeOverlayTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.settings.store', 'database');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_settings_table.php.stub';
        $migration->up();
    }

    private function reregister(): void
    {
        $this->app->forgetInstance(InjectionScreener::class);
        (new AiGuardrailsServiceProvider($this->app))->register();
    }

    public function test_a_saved_disable_actually_disables_the_control(): void
    {
        // Baseline: screening enabled (file default) → real screener.
        self::assertInstanceOf(PatternInjectionScreener::class, $this->resolve(InjectionScreener::class));

        // Persist a runtime override turning screening off, then re-boot the provider.
        DB::table('ai_guardrails_settings')->insert([
            'key' => 'input_screen.enabled',
            'value' => json_encode(false),
            'updated_at' => now(),
        ]);
        $this->reregister();

        // The override is overlaid onto config AND the control reflects it (Null screener).
        self::assertFalse(config('ai-guardrails.input_screen.enabled'));
        self::assertInstanceOf(NullInjectionScreener::class, $this->resolve(InjectionScreener::class));
    }

    public function test_corrupt_row_does_not_disable_a_control(): void
    {
        // A corrupt JSON row must never silently flip a control — the file default stands.
        DB::table('ai_guardrails_settings')->insert([
            'key' => 'input_screen.enabled',
            'value' => '{ not json',
            'updated_at' => now(),
        ]);
        $this->reregister();

        self::assertTrue(config('ai-guardrails.input_screen.enabled'));
        self::assertInstanceOf(PatternInjectionScreener::class, $this->resolve(InjectionScreener::class));
    }

    public function test_null_override_does_not_disable_a_control(): void
    {
        // A valid JSON `null` would become `(bool) null === false` — it must be rejected, not applied.
        DB::table('ai_guardrails_settings')->insert([
            'key' => 'input_screen.enabled',
            'value' => json_encode(null),
            'updated_at' => now(),
        ]);
        $this->reregister();

        self::assertTrue(config('ai-guardrails.input_screen.enabled'));
        self::assertInstanceOf(PatternInjectionScreener::class, $this->resolve(InjectionScreener::class));
    }
}
