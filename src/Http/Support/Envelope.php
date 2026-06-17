<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http\Support;

use Illuminate\Http\JsonResponse;
use Padosoft\AiGuardrails\Http\ApiSchema;

/**
 * Builds the uniform `{ schema_version, schema, data }` JSON envelope every API endpoint returns.
 */
final class Envelope
{
    public static function make(string $schema, mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'schema_version' => ApiSchema::VERSION,
            'schema' => $schema,
            'data' => $data,
        ], $status);
    }
}
