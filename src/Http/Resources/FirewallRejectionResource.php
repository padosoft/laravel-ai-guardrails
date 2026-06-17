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
        $clean = [];
        foreach ($violations as $key => $reason) {
            // Both originate from untrusted tool schemas. The key is UTF-8-scrubbed but NOT truncated
            // (truncating could collide two distinct keys and drop entries); the value is scrubbed
            // and length-bounded.
            $clean[self::utf8((string) $key)] = self::bounded(self::utf8($reason), self::VIOLATION_LIMIT);
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
