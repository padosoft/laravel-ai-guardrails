<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Support;

/**
 * Per-control enforcement mode (shadow-rollout dial):
 * - `enforce` — detect + audit + (E4) emit + BLOCK.
 * - `monitor` — detect + audit + (E4) emit, but do NOT block (observe what enforcement would do).
 * - `off`     — the control is inert (pure pass-through; nothing detected or recorded).
 */
enum ControlMode: string
{
    case Enforce = 'enforce';
    case Monitor = 'monitor';
    case Off = 'off';

    /** The control runs its detection (enforce or monitor); only `off` skips it entirely. */
    public function isActive(): bool
    {
        return $this !== self::Off;
    }

    /** The control actually blocks/refuses/parks (only in enforce). */
    public function enforces(): bool
    {
        return $this === self::Enforce;
    }

    /** Detect + record but pass through (shadow rollout). */
    public function observes(): bool
    {
        return $this === self::Monitor;
    }

    /** Parse a config value, falling back to a boolean `enabled` flag (true→enforce, false→off). */
    public static function resolve(mixed $mode, bool $enabledFallback): self
    {
        if (is_string($mode)) {
            $parsed = self::tryFrom(strtolower(trim($mode)));
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $enabledFallback ? self::Enforce : self::Off;
    }
}
