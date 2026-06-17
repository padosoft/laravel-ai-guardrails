<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use DateTimeImmutable;

/**
 * Immutable record of one screening attempt — blocked OR allowed. The append-only audit of every
 * attempt is the product value of Control B.
 */
final readonly class InjectionAttempt
{
    /**
     * @param  list<string>  $erroredRuleIds  Rule IDs that errored under on_match_error=open (forensic bypass trace).
     */
    public function __construct(
        public string $prompt,
        public bool $blocked,
        public ?string $ruleId,
        public ?string $principalId,
        public DateTimeImmutable $occurredAt,
        public ?string $rulesetVersion = null,
        public array $erroredRuleIds = [],
    ) {}
}
