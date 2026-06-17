<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;

final class NullFirewallRejectionStore implements FirewallRejectionStore
{
    public function record(FirewallRejection $rejection): void
    {
        // no-op
    }

    public function query(FirewallQueryFilters $filters): FirewallPage
    {
        return new FirewallPage([]);
    }

    public function count(): int
    {
        return 0;
    }
}
