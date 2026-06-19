<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only Eloquent model for the output-stat counter table. Updates and deletes throw — the log
 * is immutable. Rows may only be inserted (and read). GDPR erasure is performed out-of-band by a
 * sanctioned, audited maintenance path (planned as the `ai-guardrails:purge` command, Task E5).
 *
 * @property int $id
 * @property string $kind
 * @property int $event_count
 * @property string|null $detector
 * @property Carbon $occurred_at
 */
final class OutputStatRecord extends Model
{
    public $timestamps = false;

    protected $table = 'ai_guardrails_output_stats';

    /** @var list<string> */
    protected $fillable = ['kind', 'event_count', 'detector', 'occurred_at'];

    /** @var array<string,string> */
    protected $casts = [
        'event_count' => 'integer',
        'detector' => 'string',
        'occurred_at' => 'immutable_datetime',
    ];

    public function newEloquentBuilder($query): OutputStatRecordBuilder
    {
        return new OutputStatRecordBuilder($query);
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('The output stat log is append-only; records cannot be updated.');
    }

    protected function performDelete(): bool
    {
        throw new LogicException('The output stat log is append-only; records cannot be deleted.');
    }

    public function delete(): bool
    {
        throw new LogicException('The output stat log is append-only; records cannot be deleted.');
    }
}
