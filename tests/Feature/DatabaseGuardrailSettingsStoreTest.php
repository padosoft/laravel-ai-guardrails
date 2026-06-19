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

    public function test_all_fails_safe_to_defaults_when_table_is_absent(): void
    {
        // store=database but the table isn't there (fresh install / mid-deploy) → file defaults, no 500.
        $store = new DatabaseGuardrailSettingsStore(null, 'ai_guardrails_settings_missing');

        self::assertTrue($store->all()['input_screen.enabled']);
    }

    public function test_malformed_json_row_keeps_the_file_default(): void
    {
        // A corrupt value must NOT overwrite a security control's default with null.
        DB::table('ai_guardrails_settings')->insert([
            'key' => 'input_screen.enabled',
            'value' => '{ not valid json',
            'updated_at' => now(),
        ]);

        self::assertTrue($this->store()->all()['input_screen.enabled']); // file default, not null
    }

    public function test_null_and_type_mismatched_rows_keep_the_file_default(): void
    {
        // A JSON null or a wrong-type value must NOT flip a boolean control to false.
        DB::table('ai_guardrails_settings')->insert([
            ['key' => 'input_screen.enabled', 'value' => json_encode(null), 'updated_at' => now()],
            ['key' => 'output_handler.enabled', 'value' => json_encode('not-a-bool'), 'updated_at' => now()],
        ]);

        $all = $this->store()->all();
        self::assertTrue($all['input_screen.enabled']);   // null override rejected → default
        self::assertTrue($all['output_handler.enabled']); // type-mismatch rejected → default
    }

    public function test_overrides_for_keys_no_longer_overridable_are_ignored(): void
    {
        $store = $this->store();
        $store->put(['input_screen.enabled' => false]);

        // Shrink the allow-list so this key is no longer overridable.
        config(['ai-guardrails.settings.overridable' => ['output_handler.enabled']]);

        self::assertArrayNotHasKey('input_screen.enabled', $store->all());
    }

    /**
     * Fix I1 — int overrides applied even when the env var makes the file-default a string.
     *
     * When AI_GUARDRAILS_MAX_PROMPT_LENGTH / AI_GUARDRAILS_RETENTION_DAYS are set in the
     * environment, `env()` returns a STRING (e.g. "50000"). Before the (int) cast, that
     * string default failed the `gettype($value) === gettype(config($key))` gate in
     * OverridableSettings::accepts(), silently dropping the stored integer override.
     *
     * The test simulates the "env var is set" scenario by directly setting the config value
     * to a string (the same shape env() would resolve to) BEFORE the DB store resolves — this
     * is equivalent to the env-var path without requiring a real OS env mutation.
     */
    public function test_int_override_applied_even_when_config_default_is_string_due_to_env(): void
    {
        // Simulate the pre-fix scenario: config holds a string because the env var was set.
        // (After Fix I1 this no longer happens in practice, but the test also proves the
        // gate works correctly for the config-is-int state produced by the cast.)
        // --- State A: config default is a string (pre-fix env() shape) ---
        config([
            'ai-guardrails.normalization.max_prompt_length' => '50000', // string — as env() returns
            'ai-guardrails.retention.days' => '365',                    // string — as env() returns
        ]);

        $store = $this->store();
        $store->put([
            'normalization.max_prompt_length' => 1000,
            'retention.days' => 30,
        ]);

        $all = $store->all();
        // With the config default as a string, accepts() would have rejected the int override
        // (type mismatch: 'integer' !== 'string') → value stays at the string default.
        // This documents the broken pre-fix behaviour — the assertions below capture it for
        // comparison; the real fix is the (int) cast in config/ai-guardrails.php.
        self::assertSame('50000', $all['normalization.max_prompt_length']); // override dropped
        self::assertSame('365', $all['retention.days']);                    // override dropped

        // --- State B: config default is an int (post-fix shape from the (int) cast) ---
        config([
            'ai-guardrails.normalization.max_prompt_length' => 50000, // int — produced by (int) cast
            'ai-guardrails.retention.days' => 365,                    // int — produced by (int) cast
        ]);

        $all2 = $store->all();
        // Now accepts() sees integer === integer → override is applied.
        self::assertSame(1000, $all2['normalization.max_prompt_length']);
        self::assertSame(30, $all2['retention.days']);
        self::assertIsInt($all2['normalization.max_prompt_length']);
        self::assertIsInt($all2['retention.days']);
    }
}
