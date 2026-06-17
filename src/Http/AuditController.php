<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\Audit\AuditQueryFilters;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Http\Resources\AuditEntryResource;
use Padosoft\AiGuardrails\Http\Support\Envelope;

/**
 * Read-only audit endpoints over the append-only injection store: a filtered/paginated list, a
 * single-entry detail view, and an SQL-aggregated per-day trend.
 */
final class AuditController
{
    private const TREND_MAX_DAYS = 366;

    private const TREND_DEFAULT_DAYS = 30;

    public function index(Request $request, InjectionAuditStore $store): JsonResponse
    {
        $page = $store->query(AuditQueryFilters::fromRequest($request));

        return Envelope::make(ApiSchema::SCHEMA_AUDIT_LIST, [
            'entries' => array_map(AuditEntryResource::summary(...), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }

    public function show(string $id, InjectionAuditStore $store): JsonResponse
    {
        if (! ctype_digit($id)) {
            return Envelope::make(ApiSchema::SCHEMA_AUDIT_DETAIL, ['error' => 'not_found'], 404);
        }

        $attempt = $store->find((int) $id);

        if ($attempt === null) {
            return Envelope::make(ApiSchema::SCHEMA_AUDIT_DETAIL, ['error' => 'not_found'], 404);
        }

        return Envelope::make(ApiSchema::SCHEMA_AUDIT_DETAIL, ['entry' => AuditEntryResource::detail($attempt)]);
    }

    public function trend(Request $request, InjectionAuditStore $store): JsonResponse
    {
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);

        $until = $this->parseDate($request->query('to'), $utc) ?? $now;
        $from = $this->parseDate($request->query('from'), $utc)
            ?? $until->modify('-'.self::TREND_DEFAULT_DAYS.' days');

        // Clamp the window so a single request can't force an unbounded scan.
        $earliest = $until->modify('-'.self::TREND_MAX_DAYS.' days');
        if ($from < $earliest) {
            $from = $earliest;
        }
        // Ensure from never exceeds until (inverted bounds → empty result without this guard).
        if ($from > $until) {
            $from = $until;
        }

        return Envelope::make(ApiSchema::SCHEMA_AUDIT_TREND, [
            'from' => $from->format(DATE_ATOM),
            'to' => $until->format(DATE_ATOM),
            'points' => $store->trend($from, $until),
        ]);
    }

    private function parseDate(mixed $value, DateTimeZone $utc): ?DateTimeImmutable
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
            return (new DateTimeImmutable($value))->setTimezone($utc);
        } catch (\Throwable) {
            return null;
        }
    }
}
