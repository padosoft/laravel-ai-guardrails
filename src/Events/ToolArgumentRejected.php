<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use Padosoft\AiGuardrails\Firewall\FirewallRejection;

/**
 * Control A — the firewall found one or more schema/owner-key violations in a model-chosen tool
 * call. Dispatched from the same path that records the FirewallRejection, in BOTH enforce (the call
 * is then blocked) and monitor (recorded, the call proceeds) modes — the host distinguishes via the
 * control's configured mode. Carries the immutable rejection record.
 */
final readonly class ToolArgumentRejected
{
    public function __construct(
        public FirewallRejection $rejection,
    ) {}
}
