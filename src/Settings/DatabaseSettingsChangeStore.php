<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Contracts\SettingsChangeStore;

/**
 * Append-only database store for settings changes (E6). Inserts via the immutable
 * SettingsChangeRecord model (which refuses updates/deletes); reads most-recent-first.
 */
final readonly class DatabaseSettingsChangeStore implements SettingsChangeStore
{
    public function __construct(
        private ?string $connection,
        private string $table,
    ) {}

    public function record(SettingsChange $change): void
    {
        $record = new SettingsChangeRecord;
        $record->setConnection($this->connection);
        $record->setTable($this->table);
        $record->fill([
            'actor_id' => $change->actorId,
            'setting_key' => $change->key,
            'old_value' => $change->oldValue,
            'new_value' => $change->newValue,
            // Persist in UTC so the change history is unambiguous across deployments/timezones.
            'occurred_at' => $change->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ])->save();
    }

    public function recent(int $limit = 50): array
    {
        $rows = $this->baseQuery()
            ->orderByDesc('id')
            ->limit(max(0, $limit))
            ->get()
            ->all();

        return array_values(array_map($this->mapRow(...), $rows));
    }

    private function baseQuery(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }

    private function mapRow(\stdClass $row): SettingsChange
    {
        return new SettingsChange(
            $row->actor_id !== null ? (string) $row->actor_id : null,
            (string) $row->setting_key,
            $this->decode($row->old_value),
            $this->decode($row->new_value),
            new DateTimeImmutable((string) $row->occurred_at, new DateTimeZone('UTC')),
            (int) $row->id,
        );
    }

    /** JSON columns store scalars (bool/string) — decode back to the original PHP value. */
    private function decode(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode((string) $value, true);
    }
}
