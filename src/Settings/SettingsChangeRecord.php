<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only Eloquent model for the settings-change table (E6). Updates and deletes throw — the
 * change history is immutable security evidence. Rows may only be inserted (and read).
 *
 * @property int $id
 * @property string|null $actor_id
 * @property string $setting_key
 * @property mixed $old_value
 * @property mixed $new_value
 * @property Carbon $occurred_at
 */
final class SettingsChangeRecord extends Model
{
    public $timestamps = false;

    protected $table = 'ai_guardrails_settings_changes';

    /** @var list<string> */
    protected $fillable = ['actor_id', 'setting_key', 'old_value', 'new_value', 'occurred_at'];

    /** @var array<string,string> */
    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function newEloquentBuilder($query): SettingsChangeRecordBuilder
    {
        return new SettingsChangeRecordBuilder($query);
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('The settings-change log is append-only; records cannot be updated.');
    }

    protected function performDelete(): bool
    {
        throw new LogicException('The settings-change log is append-only; records cannot be deleted.');
    }

    public function delete(): bool
    {
        throw new LogicException('The settings-change log is append-only; records cannot be deleted.');
    }
}
