<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

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
    public function index(Request $request, OutputStatStore $store): JsonResponse
    {
        $from = IsoDateParser::parseUtc($request->query('from'));
        $to = IsoDateParser::parseUtc($request->query('to'));

        $totals = $store->totals($from, $to);

        // Zero-fill every kind so the UI always sees a stable, complete shape.
        $counts = [];
        foreach (OutputStatKind::values() as $kind) {
            $counts[$kind] = $totals[$kind] ?? 0;
        }

        return Envelope::make(ApiSchema::SCHEMA_OUTPUT_STATS, [
            'from' => $from?->format(DATE_ATOM),
            'to' => $to?->format(DATE_ATOM),
            'counts' => $counts,
            'total' => array_sum($counts),
        ]);
    }
}
