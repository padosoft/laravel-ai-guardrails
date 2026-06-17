<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Padosoft\AiGuardrails\Support\AppendOnlyEloquentBuilder;

/**
 * Append-only Eloquent builder for the output-stat record. All mutators throw — see
 * {@see AppendOnlyEloquentBuilder} for the rationale and the limits of this guarantee.
 *
 * @extends AppendOnlyEloquentBuilder<OutputStatRecord>
 */
final class OutputStatRecordBuilder extends AppendOnlyEloquentBuilder
{
    protected function storeLabel(): string
    {
        return 'The output stat log';
    }
}
