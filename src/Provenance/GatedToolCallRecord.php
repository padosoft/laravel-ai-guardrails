<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only Eloquent model for the Control P decision log. Updates and
 * deletes throw — the log is immutable, exactly like the firewall's.
 *
 * @property int $id
 * @property string $tool_name
 * @property string|null $principal_id
 * @property list<string> $tiers
 * @property bool $blocked
 * @property Carbon $occurred_at
 */
final class GatedToolCallRecord extends Model
{
    public $timestamps = false;

    protected $table = 'ai_guardrails_gated_tool_calls';

    /** @var list<string> */
    protected $fillable = ['tool_name', 'principal_id', 'tiers', 'blocked', 'occurred_at'];

    /** @var array<string,string> */
    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'tiers' => 'array',
        'blocked' => 'boolean',
    ];

    public function newEloquentBuilder($query): GatedToolCallRecordBuilder
    {
        return new GatedToolCallRecordBuilder($query);
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('The provenance gate log is append-only; records cannot be updated.');
    }

    protected function performDelete(): bool
    {
        throw new LogicException('The provenance gate log is append-only; records cannot be deleted.');
    }

    public function delete(): bool
    {
        throw new LogicException('The provenance gate log is append-only; records cannot be deleted.');
    }
}
