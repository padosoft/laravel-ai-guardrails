<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

/**
 * Shared helpers for the settings stores: the allow-list of overridable dotted keys and the current
 * effective file-config values for them.
 */
final class OverridableSettings
{
    /** @return list<string> The dotted keys the admin may override (from config). */
    public static function keys(): array
    {
        $keys = config('ai-guardrails.settings.overridable', []);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * The effective file-config value for each overridable key (the runtime defaults a DB store
     * overlays).
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::keys() as $key) {
            $defaults[$key] = config('ai-guardrails.'.$key);
        }

        return $defaults;
    }

    /**
     * Fail-safe gate for applying a decoded override: reject null and any value whose PHP type does
     * not match the file-config default for that key. This stops a stray/corrupt row from flipping a
     * security control (e.g. `(bool) null` → false would silently disable screening).
     */
    public static function accepts(string $key, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return gettype($value) === gettype(config('ai-guardrails.'.$key));
    }
}
