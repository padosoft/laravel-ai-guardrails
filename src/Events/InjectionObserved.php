<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use Padosoft\AiGuardrails\Audit\InjectionAttempt;

/**
 * Control B (monitor mode) — an injection attempt was DETECTED but NOT blocked (shadow rollout). The
 * prompt reached the model; the attempt is audited with `blocked=false`. Dispatched from the same
 * path that appends the audit row so operators can alert on would-have-blocked traffic.
 */
final readonly class InjectionObserved
{
    public function __construct(
        public InjectionAttempt $attempt,
    ) {}
}
