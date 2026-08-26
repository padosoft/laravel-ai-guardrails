<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use DateTimeImmutable;

/**
 * A tool call was gated (or, in monitor, would have been) because the
 * model's grounding for that invocation included material nobody in the
 * organisation wrote.
 *
 * Carries the tiers that triggered it and whether the call was actually
 * blocked, so a monitor-mode rollout produces the same event stream as
 * enforcement and an operator can size the impact before flipping the
 * switch. No arguments are carried: they are the model's, which is to say
 * possibly the attacker's, and this event lands in logs.
 */
final readonly class UntrustedGroundingToolGated
{
    /**
     * @param  list<string>  $tiers  the offending provenance tier values
     */
    public function __construct(
        public string $toolName,
        public int|string|null $principalId,
        public array $tiers,
        public bool $blocked,
        public DateTimeImmutable $occurredAt,
    ) {}
}
