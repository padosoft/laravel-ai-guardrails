<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

use DateTimeImmutable;

/**
 * Immutable record of one security-setting mutation via PUT /settings (Task E6): WHO changed WHICH
 * key, from WHAT to WHAT, and WHEN. Persisted append-only — the change history is itself audit
 * evidence and must never be rewritten. The actor is derived server-side (never client-supplied).
 */
final readonly class SettingsChange
{
    public function __construct(
        public ?string $actorId,
        public string $key,
        public mixed $oldValue,
        public mixed $newValue,
        public DateTimeImmutable $occurredAt,
        public ?int $id = null,
    ) {}
}
