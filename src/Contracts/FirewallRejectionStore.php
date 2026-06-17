<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Firewall\FirewallPage;
use Padosoft\AiGuardrails\Firewall\FirewallQueryFilters;
use Padosoft\AiGuardrails\Firewall\FirewallRejection;

interface FirewallRejectionStore
{
    /** Append a firewall rejection (Control A) to the immutable log. */
    public function record(FirewallRejection $rejection): void;

    /** Filtered, keyset-paginated query for the admin firewall list (GET /firewall). */
    public function query(FirewallQueryFilters $filters): FirewallPage;

    /** Total recorded rejections (consumed by the overview counters). */
    public function count(): int;
}
