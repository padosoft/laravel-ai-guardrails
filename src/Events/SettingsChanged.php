<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Settings\SettingsChange;

/**
 * E6 — one or more security settings were mutated via PUT /settings. Dispatched from the same path
 * that appends the change records, so a host can alert (Slack/SIEM) on configuration drift of the
 * guardrails themselves. Carries the server-derived actor and the per-key changes.
 */
final readonly class SettingsChanged
{
    /** @param  list<SettingsChange>  $changes */
    public function __construct(
        public ?string $actorId,
        public array $changes,
        public DateTimeImmutable $occurredAt,
    ) {}
}
