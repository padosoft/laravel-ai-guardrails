<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Contracts\GuardrailSettingsStore;
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
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['input_screen.patterns' => ['custom' => '\bdrop\s+table\b']],
        ])->assertOk();

        $this->getJson('/ai-guardrails/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.input_screen.patterns.custom', '\bdrop\s+table\b');
    }

    public function test_put_invalid_regex_in_patterns_returns_422_and_nothing_stored(): void
    {
        $this->putJson('/ai-guardrails/api/settings', [
            'settings' => ['input_screen.patterns' => ['bad' => '(?P<unclosed']],
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
            'settings' => ['input_screen.patterns' => ['\bfoo\b', '\bbar\b']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('settings.input_screen.patterns');
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
}
