<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Settings\SettingsChange;

interface SettingsChangeStore
{
    /** Append a settings-change record (E6) to the immutable log. */
    public function record(SettingsChange $change): void;

    /**
     * Most recent changes first (consumed by GET /settings/changes).
     *
     * @return list<SettingsChange>
     */
    public function recent(int $limit = 50): array;
}
