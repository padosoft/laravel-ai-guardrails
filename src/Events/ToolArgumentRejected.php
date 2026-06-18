<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use Padosoft\AiGuardrails\Firewall\FirewallRejection;

/**
 * Control A — the firewall found one or more schema/owner-key violations in a model-chosen tool
 * call. Dispatched from the same path that records the FirewallRejection, in BOTH enforce and
 * monitor modes. `$enforced=true` means the call was blocked (thrown); `$enforced=false` means
 * monitor mode — the violation was recorded but the call proceeded. Carries the immutable record.
 */
final readonly class ToolArgumentRejected
{
    public function __construct(
        public FirewallRejection $rejection,
        /** true = call was blocked (enforce); false = violation observed but call proceeded (monitor). */
        public bool $enforced,
    ) {}
}
