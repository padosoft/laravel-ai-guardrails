<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Custom Eloquent builder for the injection audit record. Overrides the real Builder::delete()
 * method so mass deletes via InjectionAuditRecord::query()->delete() are blocked at the query
 * level, not just at the model instance level.
 *
 * @extends Builder<InjectionAuditRecord>
 */
final class InjectionAuditRecordBuilder extends Builder
{
    public function delete(): mixed
    {
        throw new LogicException('The injection audit is append-only; records cannot be deleted.');
    }
}
