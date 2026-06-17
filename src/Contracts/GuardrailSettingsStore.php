<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

interface GuardrailSettingsStore
{
    /**
     * Effective settings for the overridable keys: file defaults overlaid with runtime overrides,
     * keyed by dotted path (e.g. `input_screen.enabled`).
     *
     * @return array<string,mixed>
     */
    public function all(): array;

    /**
     * Persist runtime overrides (already allow-list filtered + type-validated).
     *
     * @param  array<string,mixed>  $overrides  dotted-path => value
     */
    public function put(array $overrides): void;
}
