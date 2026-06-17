<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Append-only Eloquent builder for the injection audit record. Overrides every Eloquent mutator
 * that could modify existing rows (update/delete/upsert/touch/increment/decrement) so they are
 * blocked at the query level, not just on the model instance.
 *
 * Defense-in-depth only: code that drops to the base query builder (`->toBase()->update(...)`) or
 * issues raw SQL can still bypass these. The ultimate append-only guarantee is at the database
 * layer (e.g. revoke UPDATE/DELETE on the table). GDPR erasure goes through the sanctioned
 * `ai-guardrails:purge` maintenance command (Task E5), never through this builder.
 *
 * @extends Builder<InjectionAuditRecord>
 */
final class InjectionAuditRecordBuilder extends Builder
{
    public function delete(): mixed
    {
        return $this->refuse('deleted');
    }

    /** @param array<string,mixed> $values */
    public function update(array $values): mixed
    {
        return $this->refuse('updated');
    }

    /**
     * @param  array<int|string,mixed>  $values
     * @param  array<int,string>|string  $uniqueBy
     * @param  array<int,string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        return $this->refuse('updated');
    }

    /** @param string|null $column */
    public function touch($column = null): mixed
    {
        return $this->refuse('updated');
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string,mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): mixed
    {
        return $this->refuse('updated');
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string,mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): mixed
    {
        return $this->refuse('updated');
    }

    /**
     * @param  array<string,float|int>  $columns
     * @param  array<string,mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): mixed
    {
        return $this->refuse('updated');
    }

    /**
     * @param  array<string,float|int>  $columns
     * @param  array<string,mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): mixed
    {
        return $this->refuse('updated');
    }

    private function refuse(string $verb): never
    {
        throw new LogicException("The injection audit is append-only; records cannot be {$verb}.");
    }
}
