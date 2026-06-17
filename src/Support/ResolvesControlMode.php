<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Support;

/**
 * Resolves a control's effective mode from config: `modes.<control>` (enforce|monitor|off) overlaid
 * with the master kill-switch (master off → every control is Off) and a fallback to the per-control
 * `enabled` boolean for back-compat. Used by the provider to wire each control once at boot.
 */
final class ResolvesControlMode
{
    /**
     * @param  string  $control  one of: tool_firewall, input_screen, output_handler, hitl
     * @param  string  $enabledKey  the per-control boolean config key used as the fallback
     */
    public static function for(string $control, string $enabledKey): ControlMode
    {
        // Master kill-switch off → the whole package degrades to pass-through.
        if (! (bool) config('ai-guardrails.enabled', true)) {
            return ControlMode::Off;
        }

        return ControlMode::resolve(
            config("ai-guardrails.modes.{$control}"),
            (bool) config($enabledKey, true),
        );
    }
}
