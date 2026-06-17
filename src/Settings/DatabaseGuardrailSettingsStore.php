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
            $effective[(string) $row->key] = $this->decode(is_string($row->value) ? $row->value : null);
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

        // One transaction for the whole PUT: concurrent requests must not interleave at the per-row
        // level and leave the controls in a mixed state (e.g. tool_firewall.enabled vs modes.*).
        $connection->transaction(function () use ($connection, $overrides, $overridable, $now): void {
            foreach ($overrides as $key => $value) {
                if (! in_array($key, $overridable, true)) {
                    continue;
                }
                // updateOrInsert writes the value set on BOTH insert and update, so created_at is left
                // to the column default (nullable) to avoid clobbering it on every update.
                $connection->table($this->table)->updateOrInsert(
                    ['key' => $key],
                    ['value' => json_encode($value), 'updated_at' => $now],
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

    private function decode(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode($value, true);
    }
}
