<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use Padosoft\AiGuardrails\Support\AppendOnlyEloquentBuilder;

/**
 * Append-only Eloquent builder for the Control P decision log.
 *
 * @extends AppendOnlyEloquentBuilder<GatedToolCallRecord>
 */
final class GatedToolCallRecordBuilder extends AppendOnlyEloquentBuilder
{
    protected function storeLabel(): string
    {
        return 'The provenance gate log';
    }
}
