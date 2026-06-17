<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use Padosoft\AiGuardrails\Support\AppendOnlyEloquentBuilder;

/**
 * Append-only Eloquent builder for the injection audit record. All mutators throw — see
 * {@see AppendOnlyEloquentBuilder} for the rationale and the limits of this guarantee.
 *
 * @extends AppendOnlyEloquentBuilder<InjectionAuditRecord>
 */
final class InjectionAuditRecordBuilder extends AppendOnlyEloquentBuilder
{
    protected function storeLabel(): string
    {
        return 'The injection audit';
    }
}
