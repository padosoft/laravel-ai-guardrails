<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Padosoft\AiGuardrails\Contracts\GuardrailSettingsStore;
use Padosoft\AiGuardrails\Http\Requests\UpdateSettingsRequest;
use Padosoft\AiGuardrails\Http\Support\Envelope;

/**
 * Runtime settings surface: GET returns the effective overridable settings (file defaults overlaid
 * with DB overrides), PUT persists allow-listed, type-validated overrides. In config mode PUT is a
 * no-op (read-only admin).
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

    public function update(UpdateSettingsRequest $request, GuardrailSettingsStore $store): JsonResponse
    {
        $store->put($request->sanitized());

        return Envelope::make(ApiSchema::SCHEMA_SETTINGS, [
            'settings' => Arr::undot($store->all()),
        ]);
    }
}
