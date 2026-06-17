<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\Contracts\GuardrailSettingsStore;
use Padosoft\AiGuardrails\Settings\ConfigGuardrailSettingsStore;
use Padosoft\AiGuardrails\Settings\DatabaseGuardrailSettingsStore;
use Padosoft\AiGuardrails\Tests\TestCase;

final class SettingsBindingsTest extends TestCase
{
    public function test_config_store_is_the_default_and_put_is_a_no_op(): void
    {
        $store = $this->resolve(GuardrailSettingsStore::class);
        self::assertInstanceOf(ConfigGuardrailSettingsStore::class, $store);

        // Read-only: put() must not throw and must not change the effective values.
        $before = $store->all();
        $store->put(['input_screen.enabled' => false]);
        self::assertSame($before, $store->all());
    }

    public function test_database_store_is_bound_when_configured(): void
    {
        $this->app['config']->set('ai-guardrails.settings.store', 'database');
        $this->app->forgetInstance(GuardrailSettingsStore::class);

        self::assertInstanceOf(DatabaseGuardrailSettingsStore::class, $this->resolve(GuardrailSettingsStore::class));
    }

    public function test_settings_store_resolves_even_when_master_switch_off(): void
    {
        // An admin must still be able to view/edit settings to re-enable the guardrails.
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app->forgetInstance(GuardrailSettingsStore::class);

        self::assertInstanceOf(ConfigGuardrailSettingsStore::class, $this->resolve(GuardrailSettingsStore::class));
    }
}
