<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

use Padosoft\AiGuardrails\Support\AppendOnlyEloquentBuilder;

/**
 * Append-only Eloquent builder for the settings-change record. All mutators throw — see
 * {@see AppendOnlyEloquentBuilder} for the rationale and the limits of this guarantee.
 *
 * @extends AppendOnlyEloquentBuilder<SettingsChangeRecord>
 */
final class SettingsChangeRecordBuilder extends AppendOnlyEloquentBuilder
{
    protected function storeLabel(): string
    {
        return 'The settings-change log';
    }
}
