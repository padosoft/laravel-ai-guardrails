<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http\Resources;

use DateTimeZone;
use Padosoft\AiGuardrails\Firewall\FirewallRejection;

/**
 * Shapes a FirewallRejection for the HTTP API (GET /firewall). The tool description is bounded so a
 * single page can't ship unbounded text.
 */
final class FirewallRejectionResource
{
    private const TOOL_LIMIT = 200;

    private const VIOLATION_LIMIT = 500;

    private const KEY_LIMIT = 200;

    private const MAX_VIOLATIONS = 50;

    /** @return array<string, mixed> */
    public static function summary(FirewallRejection $rejection): array
    {
        return [
            'id' => $rejection->id,
            'tool' => self::bounded(self::utf8($rejection->toolDescription)),
            'principal_id' => $rejection->principalId,
            'violations' => self::violations($rejection->violations),
            'violation_count' => count($rejection->violations),
            'occurred_at' => $rejection->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }

    /**
     * @param  array<string,string>  $violations
     * @return array<string,string>
     */
    private static function violations(array $violations): array
    {
        // Keys AND values are model-controlled (unknown-argument names + reasons), so bound both — a
        // huge key would otherwise let one rejection return an arbitrarily large payload — and cap the
        // entry count. `violation_count` still reports the true total so callers see when it was capped.
        $clean = [];
        foreach ($violations as $key => $reason) {
            if (count($clean) >= self::MAX_VIOLATIONS) {
                break;
            }
            $scrubbedKey = self::utf8((string) $key);
            $boundedKey = self::bounded($scrubbedKey, self::KEY_LIMIT);
            // A truncated key could collide with another (or with a literal key already ending in the
            // suffix); keep incrementing until unique so no entry is silently dropped. The suffix
            // eats into the key budget (re-bound the base) so the result still respects KEY_LIMIT.
            $n = 2;
            while (array_key_exists($boundedKey, $clean)) {
                $suffix = ' ('.$n.')';
                $boundedKey = self::bounded($scrubbedKey, self::KEY_LIMIT - mb_strlen($suffix, 'UTF-8')).$suffix;
                $n++;
            }
            $clean[$boundedKey] = self::bounded(self::utf8($reason), self::VIOLATION_LIMIT);
        }

        return $clean;
    }

    private static function bounded(string $value, int $limit = self::TOOL_LIMIT): string
    {
        return mb_strlen($value, 'UTF-8') > $limit
            ? mb_substr($value, 0, $limit, 'UTF-8').'…'
            : $value;
    }

    /** Scrub untrusted text to valid UTF-8 so mb_* never warns and the JSON response always encodes. */
    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }
}
