<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Padosoft\AiGuardrails\Support\AppendOnlyEloquentBuilder;

/**
 * Append-only Eloquent builder for the HITL request sidecar table.
 *
 * @extends AppendOnlyEloquentBuilder<HitlRequestRecord>
 */
final class HitlRequestRecordBuilder extends AppendOnlyEloquentBuilder
{
    protected function storeLabel(): string
    {
        return 'The HITL request sidecar';
    }
}
