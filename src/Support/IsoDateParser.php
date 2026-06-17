<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Strict ISO-8601 date/datetime parser for API query bounds. Rejects PHP relative strings
 * ("tomorrow", "+1 day"), garbage, AND calendar overflow (`2026-02-30`, `2026-13-01`, `25:00`) that
 * the DateTimeImmutable constructor would silently normalise. Inputs without an explicit offset are
 * interpreted as UTC so a bare-date bound isn't shifted by the server timezone.
 */
final class IsoDateParser
{
    public static function parseUtc(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // YYYY-MM-DD with an optional time component (space or T separator, optional seconds + offset).
        // Offset (Z / ±HH:MM) sits AFTER the optional seconds so an offset is accepted with or
        // without seconds (e.g. `2026-01-15T12:00Z`, `2026-01-15T12:00:30+05:30`).
        // PREG_UNMATCHED_AS_NULL makes absent optional groups null (not '') so the time guard below
        // reads as the intended "a time was supplied" check.
        if (! preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:[+-]\d{2}:?\d{2}|Z)?)?$/',
            $value,
            $m,
            PREG_UNMATCHED_AS_NULL
        )) {
            return null;
        }

        // Reject calendar overflow the constructor would otherwise roll over (2026-02-30 → Mar 2).
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        // Reject out-of-range time components when a time is present (regex requires H+M together).
        if ($m[4] !== null) {
            $hour = (int) $m[4];
            $minute = (int) ($m[5] ?? 0);
            $second = (int) ($m[6] ?? 0);
            if ($hour > 23 || $minute > 59 || $second > 59) {
                return null;
            }
        }

        $utc = new DateTimeZone('UTC');

        try {
            return (new DateTimeImmutable($value, $utc))->setTimezone($utc);
        } catch (\Throwable) {
            return null;
        }
    }
}
