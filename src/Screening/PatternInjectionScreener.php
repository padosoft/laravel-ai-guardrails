<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Illuminate\Support\Facades\Log;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\PromptNormalizer;

/**
 * Config-driven, deterministic PCRE-regex screener. The audit trail is the value, not the pattern
 * list — every prompt is screened the same way regardless of outcome. The first matching rule wins.
 *
 * Fails CLOSED by default: if `preg_match()` errors (returns false — PCRE backtrack/recursion limit
 * or a bad-UTF-8 subject), the prompt is BLOCKED rather than silently passing the model an unscreened
 * prompt. The behaviour is configurable via `on_match_error` ('closed' = block, 'open' = skip the rule).
 * ReDoS is bounded via `pcre.backtrack_limit`. Each verdict is stamped with the ruleset version for
 * forensic reproducibility. Patterns can be validated up front with {@see validatePatterns()}.
 *
 * @param  array<string,mixed>  $patterns
 */
final readonly class PatternInjectionScreener implements InjectionScreener
{
    /** @param array<string,mixed> $patterns ruleId => PCRE pattern (non-string entries are ignored) */
    public function __construct(
        private array $patterns,
        private string $refusalMessage,
        private ?PromptNormalizer $normalizer = null,
        private int $maxPromptLength = 0,
        private string $rulesetVersion = 'v1',
        private string $onMatchError = 'closed',
        private int $backtrackLimit = 0,
    ) {}

    public function screen(string $prompt): ScreenVerdict
    {
        // Length ceiling (prompt-bombing / token exhaustion). Checked on the original prompt.
        if ($this->maxPromptLength > 0 && mb_strlen($prompt, 'UTF-8') > $this->maxPromptLength) {
            return $this->block('too_long');
        }

        // Normalize before matching so homoglyph / zero-width / case evasions cannot slip through.
        // Normalization must complete BEFORE the backtrack limit is lowered, because the normalizer
        // uses preg_replace internally and must not run under the restricted limit.
        $subject = $this->normalizer !== null ? $this->normalizer->normalize($prompt) : $prompt;

        // Lower the PCRE backtrack limit around the pattern loop only (ReDoS bound). Restored in
        // the finally block so subsequent PCRE calls in the same request are unaffected.
        $prevLimit = $this->backtrackLimit > 0 ? ini_get('pcre.backtrack_limit') : null;
        if ($this->backtrackLimit > 0) {
            ini_set('pcre.backtrack_limit', (string) $this->backtrackLimit);
        }

        /** @var list<string> $erroredRuleIds */
        $erroredRuleIds = [];

        try {
            foreach ($this->patterns as $ruleId => $pattern) {
                if (! is_string($pattern)) {
                    Log::warning('laravel-ai-guardrails: ignoring non-string screening pattern.', ['rule_id' => (string) $ruleId]);

                    continue;
                }

                // Suppress the PHP warning preg_match() emits on a PCRE/UTF-8 error: many Laravel apps
                // convert warnings to ErrorExceptions, which would bypass the fail-closed check below.
                // PREG_OFFSET_CAPTURE records the matched-pattern byte span (relative to the normalized
                // subject) for the admin's forensic highlight (Task 11).
                $matches = [];
                $result = @preg_match($pattern, $subject, $matches, PREG_OFFSET_CAPTURE);

                if ($result === false) {
                    Log::warning('laravel-ai-guardrails: screening pattern errored.', [
                        'rule_id' => (string) $ruleId,
                        'preg_error' => preg_last_error_msg(),
                        'on_match_error' => $this->onMatchError,
                    ]);

                    if ($this->onMatchError === 'open') {
                        // Fail open for THIS rule only: skip it and keep screening the rest.
                        // Record the errored rule ID so the audit trail captures the bypass.
                        $erroredRuleIds[] = (string) $ruleId;

                        continue;
                    }

                    // Fail closed: a pattern that cannot be evaluated blocks the prompt.
                    return $this->block('pattern_error:'.$ruleId);
                }

                if ($result === 1) {
                    // Carry any errored rule IDs (open mode) into the block verdict so the bypass
                    // trace is not lost when a later rule matches.
                    // With PREG_OFFSET_CAPTURE, $matches[0] is [matched string, byte offset].
                    $span = null;
                    if (isset($matches[0])) {
                        $span = [$matches[0][1], $matches[0][1] + strlen($matches[0][0])];
                    }

                    return $this->block((string) $ruleId, $erroredRuleIds, $span);
                }
            }
        } finally {
            if ($prevLimit !== false && $prevLimit !== null) {
                ini_set('pcre.backtrack_limit', $prevLimit);
            }
        }

        return $this->allow($erroredRuleIds);
    }

    /**
     * Validate patterns up front (boot-time). Returns ruleId => error message for malformed entries.
     *
     * NOTE: this validates syntax only (detects unparseable patterns). It does NOT detect
     * catastrophic-backtracking (ReDoS) patterns; those are bounded at runtime via
     * `pcre_backtrack_limit`. A syntactically valid but exponential pattern (e.g. `/(a+)+$/`)
     * will pass this check.
     *
     * @param  array<string,mixed>  $patterns
     * @return array<string,string>
     */
    public static function validatePatterns(array $patterns): array
    {
        $errors = [];

        foreach ($patterns as $ruleId => $pattern) {
            if (! is_string($pattern)) {
                $errors[(string) $ruleId] = 'pattern is not a string';

                continue;
            }

            if (@preg_match($pattern, '') === false) {
                $errors[(string) $ruleId] = preg_last_error_msg();
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $erroredRuleIds
     * @param  array{0:int,1:int}|null  $matchedSpan
     */
    private function block(string $ruleId, array $erroredRuleIds = [], ?array $matchedSpan = null): ScreenVerdict
    {
        $verdict = ScreenVerdict::block($ruleId, $this->refusalMessage)->withRulesetVersion($this->rulesetVersion);

        if ($erroredRuleIds !== []) {
            $verdict = $verdict->withErroredRuleIds($erroredRuleIds);
        }

        return $matchedSpan !== null ? $verdict->withMatchedSpan($matchedSpan) : $verdict;
    }

    /** @param list<string> $erroredRuleIds */
    private function allow(array $erroredRuleIds = []): ScreenVerdict
    {
        $verdict = ScreenVerdict::allow()->withRulesetVersion($this->rulesetVersion);

        return $erroredRuleIds !== [] ? $verdict->withErroredRuleIds($erroredRuleIds) : $verdict;
    }
}
