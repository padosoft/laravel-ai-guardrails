<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Padosoft\AiGuardrails\Contracts\InjectionScreener;

/**
 * Config-driven, deterministic regex/substring screener. The audit trail is the value, not the
 * pattern list — every prompt is screened the same way regardless of outcome. The first matching
 * rule wins. Task E1 adds normalization before matching; Task E2 adds ReDoS safety + fail-closed
 * error handling + ruleset versioning.
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
            if (preg_match($pattern, $prompt) === 1) {
                return ScreenVerdict::block((string) $ruleId, $this->refusalMessage);
            }
        }

        return ScreenVerdict::allow();
    }
}
