<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Http\Support\Envelope;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use Padosoft\AiGuardrails\Support\IsoDateParser;

/**
 * Read-only output-handler stats endpoint (Control C): per-kind counts of neutralised model output
 * (html_stripped / markdown_sanitized / structured_validation_failure / pii_redaction).
 */
final class OutputStatsController
{
    private const DEFAULT_WINDOW_DAYS = 30;

    public function index(Request $request, OutputStatStore $store): JsonResponse
    {
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);

        // Bound the default scan: with no `from`, aggregate the recent window rather than the whole
        // append-only log (which would grow linearly and get expensive if an admin UI polls this).
        // All-time totals are still reachable by passing an explicit early `from`.
        $to = IsoDateParser::parseUtc($request->query('to')) ?? $now;
        $from = IsoDateParser::parseUtc($request->query('from'))
            ?? $to->modify('-'.self::DEFAULT_WINDOW_DAYS.' days');

        // An inverted window (from after to) is left as-is: the store filters on `>= from AND <= to`,
        // which is unsatisfiable, so the totals come back genuinely empty (not a spurious zero-length
        // window). The echoed from/to make the inversion visible to the caller.
        $totals = $store->totals($from, $to);

        // Zero-fill every kind so the UI always sees a stable, complete shape.
        $counts = [];
        foreach (OutputStatKind::values() as $kind) {
            $counts[$kind] = $totals[$kind] ?? 0;
        }

        // Per-detector PII breakdown (available-branch): populated when the pii-redactor exposes
        // per-detector counts and the middleware records them with a non-null detector. When the
        // redactor is absent or does not report per-detector counts, this is always {}.
        $counts['pii'] = ['by_detector' => $store->byDetector($from, $to)];

        return Envelope::make(ApiSchema::SCHEMA_OUTPUT_STATS, [
            'from' => $from->format(DATE_ATOM),
            'to' => $to->format(DATE_ATOM),
            'counts' => $counts,
            'total' => array_sum(array_filter($counts, 'is_int')),
        ]);
    }
}
