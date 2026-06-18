<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Padosoft\AiGuardrails\Contracts\GuardrailSettingsStore;
use Padosoft\AiGuardrails\Contracts\SettingsChangeStore;
use Padosoft\AiGuardrails\Events\SettingsChanged;
use Padosoft\AiGuardrails\Http\Requests\UpdateSettingsRequest;
use Padosoft\AiGuardrails\Http\Support\Envelope;
use Padosoft\AiGuardrails\Settings\SettingsChange;

/**
 * Runtime settings surface: GET returns the effective overridable settings (file defaults overlaid
 * with DB overrides), PUT persists allow-listed, type-validated overrides. In config mode PUT is a
 * no-op (read-only admin). Every effective change is recorded to the append-only settings-change
 * audit (E6) with the SERVER-DERIVED actor, and a SettingsChanged event is dispatched.
 */
final class SettingsController
{
    public function show(GuardrailSettingsStore $store): JsonResponse
    {
        return Envelope::make(ApiSchema::SCHEMA_SETTINGS, [
            // Nest the dotted map back into a structured object for the SPA.
            'settings' => Arr::undot($store->all()),
        ]);
    }

    public function update(
        UpdateSettingsRequest $request,
        GuardrailSettingsStore $store,
        SettingsChangeStore $audit,
        Dispatcher $events,
    ): JsonResponse {
        // sanitized() may throw a 422 ValidationException (intentional) — let it propagate.
        $overrides = $request->sanitized();

        // Snapshot the effective values BEFORE the write so the audit captures the real old→new diff.
        $before = $store->all();

        try {
            $store->put($overrides);
        } catch (\Throwable $e) {
            // The read path fails safe; the write path should too. A persistence failure (table not
            // migrated, DB down) returns a deterministic 503 envelope rather than a 500.
            Log::warning('laravel-ai-guardrails: failed to persist settings.', ['exception' => $e]);

            return Envelope::make(ApiSchema::SCHEMA_SETTINGS, ['error' => 'persist_failed'], 503);
        }

        // Diff the EFFECTIVE settings before vs after the write — not the request overrides — so that
        // a no-op write (e.g. config store, which is read-only) records nothing, and only values that
        // actually changed are audited.
        $after = $store->all();
        $this->recordChanges($before, $after, $audit, $events);

        return Envelope::make(ApiSchema::SCHEMA_SETTINGS, [
            'settings' => Arr::undot($after),
        ]);
    }

    public function changes(SettingsChangeStore $audit): JsonResponse
    {
        return Envelope::make(ApiSchema::SCHEMA_SETTINGS_CHANGES, [
            'changes' => array_map(static fn (SettingsChange $c): array => [
                'id' => $c->id,
                'actor_id' => $c->actorId,
                'key' => $c->key,
                'old_value' => $c->oldValue,
                'new_value' => $c->newValue,
                'occurred_at' => $c->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            ], $audit->recent()),
        ]);
    }

    /**
     * Append a record per effectively-changed key (before !== after) and dispatch a single
     * SettingsChanged event. The actor is derived SERVER-SIDE — a client cannot spoof who changed it.
     *
     * @param  array<string,mixed>  $before  effective settings before the write
     * @param  array<string,mixed>  $after  effective settings after the write
     */
    private function recordChanges(array $before, array $after, SettingsChangeStore $audit, Dispatcher $events): void
    {
        $actorId = $this->actorId();
        $occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $changes = [];
        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ($oldValue === $newValue) {
                continue; // unchanged key — nothing to audit
            }
            $changes[] = new SettingsChange($actorId, $key, $oldValue, $newValue, $occurredAt);
        }

        if ($changes === []) {
            return;
        }

        foreach ($changes as $change) {
            $audit->record($change);
        }

        $events->dispatch(new SettingsChanged($actorId, $changes, $occurredAt));
    }

    /** The authenticated principal, resolved defensively (auth may be unbound). Never client-supplied. */
    private function actorId(): ?string
    {
        $id = rescue(static fn () => auth()->guard()->id(), null, false);

        return $id !== null ? (string) $id : null;
    }
}
