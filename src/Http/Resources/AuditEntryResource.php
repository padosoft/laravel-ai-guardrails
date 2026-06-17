<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http\Resources;

use DateTimeZone;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;

/**
 * Shapes an InjectionAttempt for the HTTP API. The full prompt is only included in the detail view;
 * the list view ships a bounded preview so a single page can't exfiltrate large prompt bodies.
 */
final class AuditEntryResource
{
    private const PREVIEW_LIMIT = 160;

    /** @return array<string, mixed> */
    public static function summary(InjectionAttempt $attempt): array
    {
        $prompt = self::utf8($attempt->prompt);

        return [
            'id' => $attempt->id,
            'blocked' => $attempt->blocked,
            'rule_id' => $attempt->ruleId,
            'ruleset_version' => $attempt->rulesetVersion,
            'prompt_preview' => self::preview($prompt),
            'prompt_length' => mb_strlen($prompt, 'UTF-8'),
            'errored' => $attempt->erroredRuleIds !== [],
            'occurred_at' => self::iso($attempt),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(InjectionAttempt $attempt): array
    {
        $prompt = self::utf8($attempt->prompt);

        // matched_span is a byte offset into the STORED prompt; if scrubbing changed the bytes it no
        // longer aligns with the returned prompt, so omit it (mirrors the screener's normalization guard).
        $span = $prompt === $attempt->prompt ? $attempt->matchedSpan : null;

        return [
            'id' => $attempt->id,
            'blocked' => $attempt->blocked,
            'rule_id' => $attempt->ruleId,
            'principal_id' => $attempt->principalId,
            'ruleset_version' => $attempt->rulesetVersion,
            'prompt' => $prompt,
            'prompt_length' => mb_strlen($prompt, 'UTF-8'),
            'errored_rule_ids' => $attempt->erroredRuleIds,
            'matched_span' => $span,
            'occurred_at' => self::iso($attempt),
        ];
    }

    private static function preview(string $prompt): string
    {
        $prompt = trim($prompt);

        return mb_strlen($prompt, 'UTF-8') > self::PREVIEW_LIMIT
            ? mb_substr($prompt, 0, self::PREVIEW_LIMIT, 'UTF-8').'…'
            : $prompt;
    }

    /**
     * Audit prompts are untrusted and may hold invalid byte sequences (the audit logs every attempt,
     * including unscreenable ones). Scrub them to valid UTF-8 so mb_* never warns/returns false and
     * the JSON response never fails to encode. Callers must pass the result through mb_* safely.
     */
    private static function utf8(string $prompt): string
    {
        return mb_check_encoding($prompt, 'UTF-8') ? $prompt : mb_scrub($prompt, 'UTF-8');
    }

    private static function iso(InjectionAttempt $attempt): string
    {
        return $attempt->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
