<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http\Resources;

use DateTimeZone;
use Padosoft\AiGuardrails\Provenance\GatedToolCall;

/**
 * Shapes a Control P decision for GET /provenance.
 *
 * The tool name is bounded and UTF-8-scrubbed on the same reasoning as
 * {@see FirewallRejectionResource}: it originates from a tool registration
 * the host controls, but "the host controls it" has never been a reason to
 * ship unbounded text through a list endpoint.
 *
 * There are deliberately no tool arguments here. They are the model's —
 * which is to say possibly the attacker's — and this payload lands in an
 * admin panel and a SIEM.
 */
final class GatedToolCallResource
{
    private const TOOL_LIMIT = 200;

    private const MAX_TIERS = 10;

    /** @return array<string, mixed> */
    public static function summary(GatedToolCall $call): array
    {
        return [
            'id' => $call->id,
            'tool' => self::bounded(self::utf8($call->toolName)),
            'principal_id' => $call->principalId,
            'tiers' => array_slice(array_map(
                static fn (string $tier): string => self::bounded(self::utf8($tier), 64),
                $call->tiers,
            ), 0, self::MAX_TIERS),
            // The field an operator sorts a monitor rollout by: false means
            // the call RAN and would have been refused under enforcement.
            'blocked' => $call->blocked,
            'occurred_at' => $call->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }

    private static function bounded(string $value, int $limit = self::TOOL_LIMIT): string
    {
        return mb_strlen($value, 'UTF-8') > $limit
            ? mb_substr($value, 0, $limit, 'UTF-8').'…'
            : $value;
    }

    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }
}
