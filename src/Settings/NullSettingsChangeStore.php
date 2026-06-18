<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

use Padosoft\AiGuardrails\Contracts\SettingsChangeStore;

/** No-op settings-change store (config mode / master kill-switch off): records nothing. */
final class NullSettingsChangeStore implements SettingsChangeStore
{
    public function record(SettingsChange $change): void {}

    public function recent(int $limit = 50): array
    {
        return [];
    }
}
