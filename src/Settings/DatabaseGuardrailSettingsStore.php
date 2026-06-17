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

        try {
            $rows = $this->rows();
        } catch (\Throwable) {
            // store=database but the table isn't there yet (fresh install / mid-deploy) → fail safe to
            // the file defaults rather than 500-ing the settings endpoint (matches overlayRuntimeSettings).
            return $effective;
        }

        // rows() already restricts to overridable keys (it may have shrunk since the row was written).
        foreach ($rows as $row) {
            if (! is_string($row->value)) {
                continue;
            }
            try {
                $decoded = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // A corrupt row must NOT silently overwrite a security control's file default — skip it.
                continue;
            }
            // Reject null / type-mismatched overrides too (fail-safe — never flip a control by accident).
            $key = (string) $row->key;
            if (OverridableSettings::accepts($key, $decoded)) {
                $effective[$key] = $decoded;
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

        // Build rows first so JSON_THROW_ON_ERROR can fail before any DB write.
        $rows = [];
        foreach ($overrides as $key => $value) {
            if (! in_array($key, $overridable, true)) {
                continue;
            }
            // Values are already type-validated; JSON_THROW_ON_ERROR fails loudly rather than
            // persisting `false` into the JSON column on an unexpected encoding error.
            $rows[] = ['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => $now];
        }

        if ($rows === []) {
            return;
        }

        // A single atomic INSERT … ON DUPLICATE KEY UPDATE / ON CONFLICT DO UPDATE avoids the
        // SELECT-then-INSERT race of updateOrInsert() that would cause a unique-key violation (HTTP 500)
        // under concurrent admin writes. created_at is NOT in the update list so the DB default
        // (useCurrent) sets it once on insert and it is never clobbered on subsequent updates.
        // This is all-or-nothing for the batch (last-writer-wins per key as expected for upserts).
        DB::connection($this->connection)
            ->table($this->table)
            ->upsert($rows, ['key'], ['value', 'updated_at']);
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
