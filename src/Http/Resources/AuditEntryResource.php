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
        return [
            'id' => $attempt->id,
            'blocked' => $attempt->blocked,
            'rule_id' => $attempt->ruleId,
            'ruleset_version' => $attempt->rulesetVersion,
            'prompt_preview' => self::preview($attempt->prompt),
            'prompt_length' => mb_strlen($attempt->prompt, 'UTF-8'),
            'errored' => $attempt->erroredRuleIds !== [],
            'occurred_at' => self::iso($attempt),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(InjectionAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'blocked' => $attempt->blocked,
            'rule_id' => $attempt->ruleId,
            'principal_id' => $attempt->principalId,
            'ruleset_version' => $attempt->rulesetVersion,
            'prompt' => $attempt->prompt,
            'prompt_length' => mb_strlen($attempt->prompt, 'UTF-8'),
            'errored_rule_ids' => $attempt->erroredRuleIds,
            'matched_span' => $attempt->matchedSpan,
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

    private static function iso(InjectionAttempt $attempt): string
    {
        return $attempt->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
