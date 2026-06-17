<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Settings;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Contracts\GuardrailSettingsStore;

/**
 * Database-backed settings store: file-config defaults overlaid with rows from the (mutable) settings
 * table. Unlike the package's audit/firewall/output logs this table is current-state, not append-only
 * — `put()` upserts. The actor-audited trail of WHO changed WHAT is a separate append-only log (E6).
 */
final readonly class DatabaseGuardrailSettingsStore implements GuardrailSettingsStore
{
    public function __construct(
        private ?string $connection,
        private string $table,
    ) {}

    public function all(): array
    {
        $effective = OverridableSettings::defaults();

        // rows() already restricts to overridable keys (it may have shrunk since the row was written).
        foreach ($this->rows() as $row) {
            if (! is_string($row->value)) {
                continue;
            }
            try {
                $effective[(string) $row->key] = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // A corrupt row must NOT silently overwrite a security control's file default with
                // null — keep the default and skip the bad row.
                continue;
            }
        }

        return $effective;
    }

    public function put(array $overrides): void
    {
        // Defence-in-depth: even though UpdateSettingsRequest allow-list-filters, a future caller
        // (console command, seeder) bypassing it must never be able to upsert a non-overridable key.
        $overridable = OverridableSettings::keys();
        $now = now();
        $connection = DB::connection($this->connection);

        // One transaction so THIS PUT's keys are written all-or-nothing (a mid-write failure can't
        // leave only some of the request's keys applied). Note: it does not serialize independent
        // concurrent PUTs — last-writer-wins per key, which is the expected upsert semantics.
        $connection->transaction(function () use ($connection, $overrides, $overridable, $now): void {
            foreach ($overrides as $key => $value) {
                if (! in_array($key, $overridable, true)) {
                    continue;
                }
                // Values are already type-validated; JSON_THROW_ON_ERROR fails loudly on an
                // unexpected encoding error rather than persisting `false` into the JSON column.
                // updateOrInsert writes the value set on BOTH insert and update, so created_at is left
                // to the column default (useCurrent) to avoid clobbering the insert time on update.
                $connection->table($this->table)->updateOrInsert(
                    ['key' => $key],
                    ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => $now],
                );
            }
        });
    }

    /** @return Collection<int,\stdClass> */
    private function rows(): Collection
    {
        // Only read rows for currently-overridable keys — bounds the result (no full-table scan into
        // memory) and ignores stale rows for keys removed from the allow-list.
        return DB::connection($this->connection)
            ->table($this->table)
            ->whereIn('key', OverridableSettings::keys())
            ->get(['key', 'value']);
    }
}
