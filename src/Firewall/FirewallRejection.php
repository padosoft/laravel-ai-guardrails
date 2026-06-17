<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use DateTimeImmutable;

/**
 * Immutable record of one tool-argument rejection by the firewall (Control A). Persisted append-only
 * and surfaced by GET /firewall so operators can see which tool calls were re-scoped/blocked and why.
 */
final readonly class FirewallRejection
{
    /**
     * @param  array<string,string>  $violations  property name => human-readable violation reason
     */
    public function __construct(
        public string $toolDescription,
        public ?string $principalId,
        public array $violations,
        public DateTimeImmutable $occurredAt,
        public ?int $id = null,
    ) {}
}
