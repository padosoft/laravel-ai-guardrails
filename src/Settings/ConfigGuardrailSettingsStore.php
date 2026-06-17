<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

use Padosoft\AiGuardrails\Contracts\GuardrailSettingsStore;

/**
 * Read-only settings store: exposes the effective file-config values for the overridable keys and
 * refuses runtime writes (the admin is read-only in config mode). Bound when settings.store=config.
 */
final class ConfigGuardrailSettingsStore implements GuardrailSettingsStore
{
    public function all(): array
    {
        return OverridableSettings::defaults();
    }

    public function put(array $overrides): void
    {
        // Config mode is read-only: there is nowhere to persist overrides. No-op.
    }
}
