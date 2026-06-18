<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use Padosoft\AiGuardrails\Audit\InjectionAttempt;

/**
 * Control B — an injection attempt was DETECTED and the prompt was refused before the model was
 * reached (enforce mode). Dispatched from the same path that appends the audit row, so the host can
 * wire SIEM/Slack/PagerDuty off a single source of truth. Carries the immutable attempt record.
 */
final readonly class InjectionBlocked
{
    public function __construct(
        public InjectionAttempt $attempt,
    ) {}
}
