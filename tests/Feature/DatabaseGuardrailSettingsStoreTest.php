<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Settings\DatabaseGuardrailSettingsStore;
use Padosoft\AiGuardrails\Tests\TestCase;

final class DatabaseGuardrailSettingsStoreTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_settings_table.php.stub';
        $migration->up();
    }

    private function store(): DatabaseGuardrailSettingsStore
    {
        return new DatabaseGuardrailSettingsStore(null, 'ai_guardrails_settings');
    }

    public function test_put_overrides_file_defaults_while_untouched_keys_stay_default(): void
    {
        $store = $this->store();

        // input_screen.enabled defaults to true in config.
        self::assertTrue($store->all()['input_screen.enabled']);

        $store->put(['input_screen.enabled' => false]);

        $all = $store->all();
        self::assertFalse($all['input_screen.enabled']);          // overridden
        self::assertTrue($all['output_handler.enabled']);          // untouched → still the config default
    }

    public function test_put_is_an_upsert_not_a_duplicate(): void
    {
        $store = $this->store();

        $store->put(['hitl.fallback' => 'pass']);
        $store->put(['hitl.fallback' => 'deny']);

        self::assertSame('deny', $store->all()['hitl.fallback']);
        self::assertSame(1, DB::table('ai_guardrails_settings')->where('key', 'hitl.fallback')->count());
    }

    public function test_put_refuses_to_persist_non_overridable_keys(): void
    {
        // Defence-in-depth: a caller bypassing UpdateSettingsRequest must not upsert arbitrary keys.
        $this->store()->put(['audit.store' => 'database', 'input_screen.enabled' => false]);

        self::assertSame(0, DB::table('ai_guardrails_settings')->where('key', 'audit.store')->count());
        self::assertSame(1, DB::table('ai_guardrails_settings')->where('key', 'input_screen.enabled')->count());
    }

    public function test_overrides_for_keys_no_longer_overridable_are_ignored(): void
    {
        $store = $this->store();
        $store->put(['input_screen.enabled' => false]);

        // Shrink the allow-list so this key is no longer overridable.
        config(['ai-guardrails.settings.overridable' => ['output_handler.enabled']]);

        self::assertArrayNotHasKey('input_screen.enabled', $store->all());
    }
}
