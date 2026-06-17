<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Filters + keyset-pagination cursor for the audit list endpoint (GET /audit). The cursor is the
 * id of the last row seen on the previous page (rows are returned newest-first by id).
 */
final readonly class AuditQueryFilters
{
    public function __construct(
        public ?bool $blocked = null,
        public ?string $ruleId = null,
        public ?string $principalId = null,
        public ?string $search = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $limit = 50,
        public ?int $cursor = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $blocked = $request->query('blocked');
        $limit = (int) $request->query('limit', '50');
        $cursor = $request->query('cursor');
        $from = self::parseDate($request->query('from'));
        $to = self::parseDate($request->query('to'));

        return new self(
            blocked: $blocked === null ? null : filter_var($blocked, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ruleId: self::str($request->query('rule_id')),
            principalId: self::str($request->query('principal_id')),
            search: self::str($request->query('q')),
            from: $from,
            to: $to,
            limit: max(1, min(200, $limit)),
            cursor: is_numeric($cursor) ? (int) $cursor : null,
        );
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Reject PHP relative strings ("tomorrow", "next year", "+1 day", …). Only accept strict
        // ISO 8601: YYYY-MM-DD with an optional time component (space or T separator).
        if (! preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}([+-]\d{2}:?\d{2}|Z)?)?)?$/', $value)) {
            return null;
        }

        try {
            // Interpret inputs without an explicit offset as UTC, matching how occurred_at is stored,
            // so a bare-date filter bound isn't shifted by the server timezone.
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
