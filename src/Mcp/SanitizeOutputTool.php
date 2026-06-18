<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Padosoft\AiGuardrails\Facades\AiGuardrails;

/**
 * MCP tool (Task L5): sanitize an untrusted text blob through Control C (HTML/markdown neutralisation
 * + PII redaction) and return the cleaned text. Vendor reference confined to src/Mcp.
 */
final class SanitizeOutputTool extends Tool
{
    protected string $name = 'sanitize_output';

    protected string $description = 'Sanitize untrusted model output: escape/neutralise HTML and markdown exfiltration vectors and redact PII. Returns the cleaned text.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The untrusted text to sanitize.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return Response::json([
            'text' => AiGuardrails::sanitize((string) $request->get('text', '')),
        ]);
    }
}
