<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only Eloquent builder base for the package's immutable log tables (injection audit,
 * firewall rejections, …). Overrides every Eloquent mutator that could modify existing rows
 * (update/delete/upsert/touch/increment/decrement) so they are blocked at the query level, not just
 * on the model instance.
 *
 * Defense-in-depth only: code that drops to the base query builder (`->toBase()->update(...)`) or
 * issues raw SQL can still bypass these. The ultimate append-only guarantee is at the database layer
 * (e.g. revoke UPDATE/DELETE on the table). GDPR erasure is performed out-of-band by a sanctioned,
 * audited maintenance path (planned as the `ai-guardrails:purge` command, Task E5) — never here.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
abstract class AppendOnlyEloquentBuilder extends Builder
{
    /** A human label for the store, used in the refusal message (e.g. "The injection audit"). */
    abstract protected function storeLabel(): string;

    public function delete(): mixed
    {
        return $this->refuse('deleted');
    }

    /** @param array<string,mixed> $values */
    public function update(array $values): mixed
    {
        return $this->refuse('updated');
    }

    public function truncate(): mixed
    {
        return $this->refuse('truncated');
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
        throw new LogicException("{$this->storeLabel()} is append-only; records cannot be {$verb}.");
    }
}
