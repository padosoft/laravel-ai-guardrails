<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Illuminate\Support\Facades\Log;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;

/**
 * Config-driven, deterministic PCRE-regex screener. The audit trail is the value, not the pattern
 * list — every prompt is screened the same way regardless of outcome. The first matching rule wins.
 *
 * Fails CLOSED: if `preg_match()` errors (returns false — e.g. PCRE backtrack/recursion limit or a
 * bad-UTF-8 subject), the prompt is treated as blocked rather than silently passing the model an
 * unscreened prompt (which would be a bypass). Task E1 adds normalization before matching; Task E2
 * makes the error behaviour configurable (on_match_error) and adds ReDoS limits + ruleset versioning.
 *
 * @param  array<string,string>  $patterns
 */
final readonly class PatternInjectionScreener implements InjectionScreener
{
    /** @param array<string,string> $patterns ruleId => PCRE pattern */
    public function __construct(
        private array $patterns,
        private string $refusalMessage,
    ) {}

    public function screen(string $prompt): ScreenVerdict
    {
        foreach ($this->patterns as $ruleId => $pattern) {
            $result = preg_match($pattern, $prompt);

            if ($result === false) {
                // PCRE error → fail closed (block), never fail open.
                Log::warning('laravel-ai-guardrails: screening pattern errored; failing closed.', [
                    'rule_id' => (string) $ruleId,
                    'preg_error' => preg_last_error_msg(),
                ]);

                return ScreenVerdict::block('pattern_error:'.$ruleId, $this->refusalMessage);
            }

            if ($result === 1) {
                return ScreenVerdict::block((string) $ruleId, $this->refusalMessage);
            }
        }

        return ScreenVerdict::allow();
    }
}
