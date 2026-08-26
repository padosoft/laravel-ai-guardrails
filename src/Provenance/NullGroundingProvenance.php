<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use Padosoft\AiGuardrails\Contracts\GroundingProvenance;

/**
 * The default: nothing is known about this invocation's grounding.
 *
 * **This fails open, and that is a deliberate trade rather than an
 * oversight.** An app that has never labelled its corpus has no tiers to
 * report, so a fail-closed default would gate every tool call in every such
 * app on day one — which would not make anyone safer, it would make the
 * control something nobody switches on. The honest position is: this
 * package cannot invent knowledge the host has not recorded.
 *
 * What follows from that is worth stating plainly to whoever enables the
 * control: **its value is exactly as good as the labelling behind it.**
 * Turning on `provenance.enabled` in an app whose retrieval layer reports
 * nothing buys precisely nothing, and the `provenance:status` line in the
 * package's own diagnostics says so rather than showing a reassuring green.
 *
 * @api
 */
final class NullGroundingProvenance implements GroundingProvenance
{
    /**
     * @return list<ProvenanceTier>
     */
    public function tiers(): array
    {
        return [];
    }
}
