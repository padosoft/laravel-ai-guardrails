<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Contracts\GuardrailSettingsStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Settings\ConfigGuardrailSettingsStore;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Task 5 — Widen settings.overridable allow-list with validation.
 *
 * New key types: string_list (owner_keys, destructive_tools), regex_map (input_screen.patterns),
 * int_positive (normalization.max_prompt_length), int_nonneg (retention.days), bool (normalization.*),
 * enum (audit_hygiene.prompt_storage, retention.strategy).
 */
final class SettingsWideAllowListTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.settings.store', 'database');
        $app['config']->set('ai-guardrails.settings_audit.store', 'database');
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

        (require __DIR__.'/../../../database/migrations/create_ai_guardrails_settings_table.php.stub')->up();
        (require __DIR__.'/../../../database/migrations/create_ai_guardrails_settings_changes_table.php.stub')->up();
    }

    // ── string_list (tool_firewall.owner_keys, hitl.destructive_tools) ───────────

    public function test_put_owner_keys_array_persists_and_get_reflects_it(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['tool_firewall.owner_keys' => ['user_id', 'tenant_id']],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.tool_firewall.owner_keys', ['user_id', 'tenant_id']);
    }

    public function test_put_owner_keys_not_array_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['tool_firewall.owner_keys' => 'not_an_array'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.tool_firewall.owner_keys');
    }

    public function test_put_owner_keys_with_non_string_element_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['tool_firewall.owner_keys' => [1, 2]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.tool_firewall.owner_keys');
    }

    public function test_put_owner_keys_with_empty_string_element_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['tool_firewall.owner_keys' => ['user_id', '']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.tool_firewall.owner_keys');
    }

    public function test_put_destructive_tools_array_persists(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['hitl.destructive_tools' => ['wipe', 'purge']],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.hitl.destructive_tools', ['wipe', 'purge']);
    }

    // ── regex_map (input_screen.patterns) ────────────────────────────────────────

    public function test_put_valid_patterns_map_persists(): void
    {
        // Patterns MUST be submitted in fully-delimited format (same as the config defaults and as the
        // screener runs them). Body-only patterns (without delimiters) are rejected because they would
        // cause preg_match errors at screening time, breaking screening entirely.
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['input_screen.patterns' => ['custom' => '/\bdrop\b/iu']],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.input_screen.patterns.custom', '/\bdrop\b/iu');
    }

    public function test_put_body_only_pattern_without_delimiters_returns_422(): void
    {
        // A body-only pattern (no delimiters) passes the old validator but would fail at screening time
        // because the screener calls preg_match($pattern, ...) expecting a delimited pattern.
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['input_screen.patterns' => ['bad' => 'no-delimiters']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.input_screen.patterns');
    }

    public function test_put_invalid_regex_in_patterns_returns_422_and_nothing_stored(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['input_screen.patterns' => ['bad' => '/(?P<unclosed/']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.input_screen.patterns');

        self::assertSame(
            0,
            DB::table('ai_guardrails_settings')->where('key', 'input_screen.patterns')->count()
        );
    }

    public function test_put_patterns_as_list_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['input_screen.patterns' => ['/\bfoo\b/u', '/\bbar\b/u']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.input_screen.patterns');
    }

    public function test_put_mixed_patterns_map_no_partial_write(): void
    {
        // Fix 3: if ANY pattern is invalid the entire map must be rejected and NOTHING stored.
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['input_screen.patterns' => [
                'good' => '/\bdrop\b/iu',
                'bad' => '/(?P<unclosed/',
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.input_screen.patterns');

        self::assertSame(
            0,
            DB::table('ai_guardrails_settings')->where('key', 'input_screen.patterns')->count(),
            'No row must be stored when the map contains even one invalid pattern (no partial write).'
        );
    }

    // ── int_positive (normalization.max_prompt_length) ───────────────────────────

    public function test_put_max_prompt_length_valid_persists(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['normalization.max_prompt_length' => 1000],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.normalization.max_prompt_length', 1000);
    }

    public function test_put_max_prompt_length_zero_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['normalization.max_prompt_length' => 0],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.normalization.max_prompt_length');
    }

    public function test_put_max_prompt_length_negative_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['normalization.max_prompt_length' => -1],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.normalization.max_prompt_length');
    }

    // ── int_nonneg (retention.days) ──────────────────────────────────────────────

    public function test_put_retention_days_zero_is_accepted(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['retention.days' => 0],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.retention.days', 0);
    }

    public function test_put_retention_days_negative_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['retention.days' => -5],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.retention.days');
    }

    // ── enum validation ──────────────────────────────────────────────────────────

    public function test_put_bad_enum_audit_hygiene_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['audit_hygiene.prompt_storage' => 'bad'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.audit_hygiene.prompt_storage');
    }

    public function test_put_valid_audit_hygiene_enum_persists(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['audit_hygiene.prompt_storage' => 'hash'],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.audit_hygiene.prompt_storage', 'hash');
    }

    public function test_put_bad_enum_retention_strategy_returns_422(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['retention.strategy' => 'explode'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.retention.strategy');
    }

    public function test_put_valid_retention_strategy_persists(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['retention.strategy' => 'purge'],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.retention.strategy', 'purge');
    }

    // ── normalization booleans ───────────────────────────────────────────────────

    public function test_put_valid_normalization_booleans_persists(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => [
                'normalization.nfkc' => false,
                'normalization.strip_zero_width' => false,
                'normalization.casefold' => false,
                'normalization.decode_base64_blobs' => true,
                'normalization.fold_confusables' => false,
            ],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.normalization.nfkc', false)
            ->assertJsonPath('data.settings.normalization.strip_zero_width', false)
            ->assertJsonPath('data.settings.normalization.casefold', false)
            ->assertJsonPath('data.settings.normalization.decode_base64_blobs', true)
            ->assertJsonPath('data.settings.normalization.fold_confusables', false);
    }

    public function test_put_normalization_bool_accepts_integer_one_zero(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['normalization.nfkc' => 0],
        ])->assertOk()
            ->assertJsonPath('data.settings.normalization.nfkc', false);
    }

    // ── non-overridable keys silently dropped ────────────────────────────────────

    public function test_put_non_overridable_hitl_requests_store_is_silently_dropped(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => [
                'hitl_requests.store' => 'database',
                'retention.days' => 30,
            ],
        ])->assertOk();

        self::assertSame(
            0,
            DB::table('ai_guardrails_settings')->where('key', 'hitl_requests.store')->count()
        );
        self::assertSame(
            1,
            DB::table('ai_guardrails_settings')->where('key', 'retention.days')->count()
        );
    }

    // ── change-record written for a new key ──────────────────────────────────────

    public function test_put_retention_days_records_change_row(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['retention.days' => 30],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings/changes')
            ->assertOk()
            ->assertJsonPath('data.changes.0.key', 'retention.days')
            ->assertJsonPath('data.changes.0.new_value', 30);
    }

    // ── JSON array/enum round-trip through the DB store ──────────────────────────

    public function test_owner_keys_and_enum_round_trip_through_database_store(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => [
                'tool_firewall.owner_keys' => ['user_id', 'tenant_id', 'account_id'],
                'audit_hygiene.prompt_storage' => 'hash',
            ],
        ])->assertOk();

        $response = $this->getJson('/ai-guardrails/api/settings')->assertOk();

        self::assertSame(
            ['user_id', 'tenant_id', 'account_id'],
            $response->json('data.settings.tool_firewall.owner_keys')
        );
        self::assertSame('hash', $response->json('data.settings.audit_hygiene.prompt_storage'));
    }

    // ── config store: PUT is a no-op (existing contract) ─────────────────────────

    public function test_config_store_put_is_noop(): void
    {
        // Rebind to the read-only config store so PUT is a no-op regardless of the DB.
        $this->app->bind(GuardrailSettingsStore::class, ConfigGuardrailSettingsStore::class);

        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['retention.days' => 999],
        ])->assertOk();

        // Config mode is read-only: the effective value is still the file default (365).
        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.retention.days', 365);
    }

    // ── Fix 2: strict integer coercion (max_prompt_length) ───────────────────────

    public function test_put_max_prompt_length_string_integer_accepted(): void
    {
        // A strictly-numeric integer string (no whitespace, no decimal point) must be coerced and accepted.
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['normalization.max_prompt_length' => '1000'],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.normalization.max_prompt_length', 1000);
    }

    public function test_put_max_prompt_length_string_with_whitespace_is_accepted_after_trim(): void
    {
        // " 5 " with surrounding spaces is sent as a JSON string. Laravel's TrimStrings middleware
        // trims it to "5" (a strictly-numeric string) BEFORE it reaches our validator. After trimming
        // it is accepted and coerced to the integer 5. This is the correct end-to-end HTTP behaviour.
        // The underlying coerceIntRange uses preg_match('/^-?\d+$/') rather than filter_var, so if a
        // raw " 5 " ever reached it without trimming it would be rejected — verified by unit test.
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['normalization.max_prompt_length' => ' 5 '],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.normalization.max_prompt_length', 5);
    }

    public function test_put_max_prompt_length_alphanumeric_string_returns_422(): void
    {
        // "5x" must be rejected — not a pure integer string, and TrimStrings cannot fix it.
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['normalization.max_prompt_length' => '5x'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.normalization.max_prompt_length');
    }

    // ── Fix 1 end-to-end: stored override flows into the screener at runtime ─────

    /**
     * Proves that a delimited pattern stored via PUT /settings is actually enforced by the screener on
     * the next request — i.e. the format stored (delimited) matches the format the screener expects.
     *
     * The override is written to the DB, then we rebuild the screener singleton (simulating the
     * next-boot overlay) by overlaying the stored value onto the config, forgetting the screener
     * singleton, and re-registering — the same path the ServiceProvider's overlayRuntimeSettings()
     * + register() use. POST /try/screen then proves the runtime effect.
     */
    public function test_stored_delimited_pattern_is_enforced_at_screening_time(): void
    {
        // 1. Store a delimited pattern via the settings API.
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['input_screen.patterns' => ['custom' => '/\bwipe\s+database\b/iu']],
        ])->assertOk();

        // 2. Simulate overlay: fetch the stored value and apply it to the config, then rebuild the
        //    screener — mirrors what overlayRuntimeSettings() + a new boot cycle would do.
        $stored = DB::table('ai_guardrails_settings')
            ->where('key', 'input_screen.patterns')
            ->value('value');

        self::assertNotNull($stored, 'Pattern override must be persisted in the DB.');

        /** @var array<string,string> $patterns */
        $patterns = json_decode((string) $stored, true);
        $this->app['config']->set('ai-guardrails.input_screen.patterns', $patterns);

        // Forget and re-register the screener so it picks up the new config value.
        $this->app->forgetInstance(InjectionScreener::class);
        (new AiGuardrailsServiceProvider($this->app))->register();

        // 3. Screen the matching prompt — must be BLOCKED with rule_id 'custom'.
        $this->postJson('/ai-guardrails/api/try/screen', ['prompt' => 'please wipe database now'])
            ->assertOk()
            ->assertJsonPath('data.blocked', true)
            ->assertJsonPath('data.rule_id', 'custom');

        // 4. Screen a benign prompt — must be ALLOWED.
        $this->postJson('/ai-guardrails/api/try/screen', ['prompt' => 'what is the refund policy?'])
            ->assertOk()
            ->assertJsonPath('data.blocked', false);
    }
}
