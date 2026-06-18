<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Support;

/**
 * Resolves a control's effective mode from config. Two gates then a posture:
 *  1. master kill-switch off (`ai-guardrails.enabled=false`) → every control is Off;
 *  2. the per-control `enabled` boolean off → that control is Off (preserves the original on/off
 *     semantics, so an explicit `enabled=false` always disables — back-compat with Tasks 2–5);
 *  3. otherwise `modes.<control>` (enforce|monitor|off) selects the posture among enabled controls,
 *     defaulting to `enforce` when unset/unrecognised.
 *
 * In short: `enabled` decides WHETHER a control runs; `modes.<control>` decides HOW (block vs observe).
 * Used by the provider to wire each control once at boot.
 */
final class ResolvesControlMode
{
    /**
     * @param  string  $control  one of: tool_firewall, input_screen, output_handler, hitl
     * @param  string  $enabledKey  the per-control boolean config key that gates the control
     */
    public static function for(string $control, string $enabledKey): ControlMode
    {
        // Master kill-switch off → the whole package degrades to pass-through.
        if (! (bool) config('ai-guardrails.enabled', true)) {
            return ControlMode::Off;
        }

        // Per-control disabled → inert, regardless of the configured mode.
        if (! (bool) config($enabledKey, true)) {
            return ControlMode::Off;
        }

        // Enabled → the mode chooses the posture; unknown/unset falls back to enforce.
        return ControlMode::resolve(config("ai-guardrails.modes.{$control}"), true);
    }
}
