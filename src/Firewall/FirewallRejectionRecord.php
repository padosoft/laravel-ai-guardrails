<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only Eloquent model for the firewall rejection table. Updates and deletes throw — the log
 * is immutable. Rows may only be inserted (and read). GDPR erasure is performed out-of-band by a
 * sanctioned, audited maintenance path (planned as the `ai-guardrails:purge` command, Task E5).
 *
 * @property int $id
 * @property string $tool_description
 * @property string|null $principal_id
 * @property array<string,string> $violations
 * @property Carbon $occurred_at
 */
final class FirewallRejectionRecord extends Model
{
    public $timestamps = false;

    protected $table = 'ai_guardrails_firewall_rejections';

    /** @var list<string> */
    protected $fillable = ['tool_description', 'principal_id', 'violations', 'occurred_at'];

    /** @var array<string,string> */
    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'violations' => 'array',
    ];

    public function newEloquentBuilder($query): FirewallRejectionRecordBuilder
    {
        return new FirewallRejectionRecordBuilder($query);
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('The firewall rejection log is append-only; records cannot be updated.');
    }

    protected function performDelete(): bool
    {
        throw new LogicException('The firewall rejection log is append-only; records cannot be deleted.');
    }

    public function delete(): bool
    {
        throw new LogicException('The firewall rejection log is append-only; records cannot be deleted.');
    }
}
