<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use Padosoft\AiGuardrails\Contracts\GroundingProvenance;

/**
 * A per-invocation holder the host pushes tiers into as it assembles
 * grounding, for apps whose retrieval layer has no natural place to answer
 * a query from.
 *
 * Bind it as a scoped singleton and record each retrieved chunk's tier:
 *
 * ```php
 * $provenance = app(RequestGroundingProvenance::class);
 *
 * foreach ($chunks as $chunk) {
 *     $provenance->record(ProvenanceTier::from($chunk->document->provenance));
 * }
 * ```
 *
 * Scope matters: this accumulates, and it does not know when one model
 * invocation ends and the next begins. In a queue worker, where the
 * container outlives the job, `reset()` between invocations is the
 * caller's responsibility — otherwise one email-derived chunk taints every
 * later job in that worker's lifetime. That is a leak toward MORE gating
 * rather than less, so it fails safe, but it will also look like the
 * control has gone haywire.
 *
 * @api
 */
final class RequestGroundingProvenance implements GroundingProvenance
{
    /** @var array<string, ProvenanceTier> */
    private array $seen = [];

    public function record(ProvenanceTier $tier): void
    {
        $this->seen[$tier->value] = $tier;
    }

    public function reset(): void
    {
        $this->seen = [];
    }

    /**
     * @return list<ProvenanceTier>
     */
    public function tiers(): array
    {
        return array_values($this->seen);
    }
}
