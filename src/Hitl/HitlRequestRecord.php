<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only Eloquent model for the HITL request sidecar table. Updates and deletes throw — the
 * sidecar is immutable. Rows may only be inserted (and read).
 *
 * @property int $id
 * @property string $run_id
 * @property string|null $approval_id
 * @property string $tool
 * @property array<string,mixed> $arguments
 * @property string|null $principal_id
 * @property Carbon $occurred_at
 */
final class HitlRequestRecord extends Model
{
    public $timestamps = false;

    protected $table = 'ai_guardrails_hitl_requests';

    /** @var list<string> */
    protected $fillable = ['run_id', 'approval_id', 'tool', 'arguments', 'principal_id', 'occurred_at'];

    /** @var array<string,string> */
    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'arguments' => 'array',
    ];

    public function newEloquentBuilder($query): HitlRequestRecordBuilder
    {
        return new HitlRequestRecordBuilder($query);
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('The HITL request sidecar is append-only; records cannot be updated.');
    }

    protected function performDelete(): bool
    {
        throw new LogicException('The HITL request sidecar is append-only; records cannot be deleted.');
    }

    public function delete(): bool
    {
        throw new LogicException('The HITL request sidecar is append-only; records cannot be deleted.');
    }
}
