<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Provenance\NullGroundingProvenance;
use Padosoft\AiGuardrails\Provenance\ProvenanceTier;

/**
 * Reports the provenance tiers present in the grounding material the model
 * had in context for the CURRENT invocation.
 *
 * The host implements this, because only the host knows its own retrieval
 * pipeline: which chunks were selected, which document each came from, and
 * how that document was labelled at ingest. This package deliberately does
 * not try to infer any of it.
 *
 * @api
 */
interface GroundingProvenance
{
    /**
     * The distinct tiers present right now. An empty list means "nothing is
     * known about this invocation's grounding" — which is NOT the same as
     * "the grounding is trusted", and the gate treats it accordingly (see
     * the fail-open note on {@see NullGroundingProvenance}).
     *
     * @return list<ProvenanceTier>
     */
    public function tiers(): array;
}
