<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

use Padosoft\AiGuardrails\Contracts\SettingsChangeStore;

/** In-memory append-only settings-change store (tests / default). Assigns a sequential id on record. */
final class ArraySettingsChangeStore implements SettingsChangeStore
{
    /** @var list<SettingsChange> */
    private array $changes = [];

    private int $nextId = 1;

    public function record(SettingsChange $change): void
    {
        $this->changes[] = new SettingsChange(
            $change->actorId,
            $change->key,
            $change->oldValue,
            $change->newValue,
            $change->occurredAt,
            $this->nextId++,
        );
    }

    public function recent(int $limit = 50): array
    {
        return array_slice(array_reverse($this->changes), 0, max(0, $limit));
    }
}
