<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\Contracts\GatedToolCallStore;
use Padosoft\AiGuardrails\Http\Resources\GatedToolCallResource;
use Padosoft\AiGuardrails\Http\Support\Envelope;
use Padosoft\AiGuardrails\Provenance\GatedToolCallFilters;

/**
 * Read-only Control P endpoint: a filtered, keyset-paginated list of tool
 * calls the provenance gate refused — or, with `?blocked=0`, the ones it
 * merely observed.
 *
 * That second case is the point of the endpoint. Monitor mode exists so an
 * operator can size the impact on real traffic before enforcing, and until
 * now the events it emitted had nowhere to be read.
 */
final class ProvenanceController
{
    public function index(Request $request, GatedToolCallStore $store): JsonResponse
    {
        $page = $store->query(GatedToolCallFilters::fromRequest($request));

        return Envelope::make(ApiSchema::SCHEMA_PROVENANCE, [
            'entries' => array_map(GatedToolCallResource::summary(...), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
