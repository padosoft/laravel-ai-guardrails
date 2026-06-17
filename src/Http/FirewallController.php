<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\FirewallQueryFilters;
use Padosoft\AiGuardrails\Http\Resources\FirewallRejectionResource;
use Padosoft\AiGuardrails\Http\Support\Envelope;

/**
 * Read-only firewall endpoint (Control A): a filtered, keyset-paginated list of recorded
 * tool-argument rejections.
 */
final class FirewallController
{
    public function index(Request $request, FirewallRejectionStore $store): JsonResponse
    {
        $page = $store->query(FirewallQueryFilters::fromRequest($request));

        return Envelope::make(ApiSchema::SCHEMA_FIREWALL, [
            'entries' => array_map(FirewallRejectionResource::summary(...), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
