<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use Padosoft\AiGuardrails\Contracts\InjectionScreener;

/**
 * Where a piece of grounding material was authored.
 *
 * The distinction this package already draws with {@see InjectionScreener}
 * is *detection*: look at the text, guess whether someone is trying to
 * hijack the model. That is an arms race, and it is one the defender loses
 * eventually — every pattern list is a list of the attacks somebody already
 * thought of.
 *
 * Provenance is not detection. It is a **fact recorded at ingest**: this
 * paragraph came out of a mailbox that anyone on the internet can write to.
 * No pattern matching, nothing to evade. A screener guesses; provenance
 * knows.
 *
 * The three tiers are deliberately the same three AskMyDocs assigns at
 * ingest time, so a corpus labelled there can be read here without a
 * translation layer that would inevitably drift.
 *
 * @api
 */
enum ProvenanceTier: string
{
    /**
     * Authored inside the organisation by someone who already has access —
     * a handbook, a policy page, an internal wiki.
     */
    case TrustedInternal = 'trusted_internal';

    /**
     * Authored by someone outside, or by anyone at all: an inbound email, a
     * scraped page, a supplier-supplied product description, a customer's
     * ticket body. Not necessarily hostile — just not ours.
     */
    case UntrustedExternal = 'untrusted_external';

    /**
     * Produced by a model. Untrusted in the same way, but distinguishable,
     * because the remedy differs: you can stop ingesting an external
     * source, and you cannot stop your own summariser from summarising.
     */
    case MachineGenerated = 'machine_generated';

    /** Whether material at this tier may have been written by someone we do not control. */
    public function isUntrusted(): bool
    {
        return $this !== self::TrustedInternal;
    }
}
