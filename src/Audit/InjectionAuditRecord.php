<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only Eloquent model for the injection audit table. Updates and deletes throw — the audit
 * trail is immutable. Rows may only be inserted (and read). GDPR erasure goes through the sanctioned
 * `ai-guardrails:purge` maintenance command (Task E5), never through this model.
 *
 * @property int $id
 * @property string $prompt
 * @property bool $blocked
 * @property string|null $rule_id
 * @property string|null $principal_id
 * @property Carbon $occurred_at
 */
final class InjectionAuditRecord extends Model
{
    public $timestamps = false;

    protected $table = 'ai_guardrails_injection_audit';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'blocked' => 'boolean',
        'occurred_at' => 'immutable_datetime',
    ];

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('The injection audit is append-only; records cannot be updated.');
    }

    public function delete(): bool
    {
        throw new LogicException('The injection audit is append-only; records cannot be deleted.');
    }
}
