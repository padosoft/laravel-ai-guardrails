<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use DateTimeImmutable;

/**
 * Immutable record of one tool call Control P gated — or, in `monitor`,
 * would have gated.
 *
 * Monitor-mode rows are the reason this store exists. The event fires
 * identically in both modes so an operator can size the blast radius on
 * real traffic before enforcing, and an event nobody can read afterwards
 * does not let them do that.
 *
 * Deliberately carries NO tool arguments. They are the model's, which is
 * to say possibly the attacker's, and this row is read in an admin panel
 * and shipped to SIEM. The tool name, the principal and the offending
 * tiers are the whole decision; the arguments would only add exposure.
 */
final readonly class GatedToolCall
{
    /**
     * @param  list<string>  $tiers  the offending provenance tier values
     */
    public function __construct(
        public string $toolName,
        public ?string $principalId,
        public array $tiers,
        public bool $blocked,
        public DateTimeImmutable $occurredAt,
        public ?int $id = null,
    ) {}
}
