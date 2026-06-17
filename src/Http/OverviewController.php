<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use Illuminate\Http\JsonResponse;
use Padosoft\AiGuardrails\Http\Support\Envelope;
use Padosoft\AiGuardrails\Overview\OverviewAggregator;

final class OverviewController
{
    public function index(OverviewAggregator $aggregator): JsonResponse
    {
        return Envelope::make(ApiSchema::SCHEMA_OVERVIEW, $aggregator->aggregate());
    }
}
