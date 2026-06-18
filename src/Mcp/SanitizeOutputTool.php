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

    /** Maximum number of characters accepted per call (guards against CPU/memory amplification via the sanitisation pipeline). */
    private const MAX_TEXT_LENGTH = 65_535;

    protected string $description = 'Sanitize untrusted model output: escape/neutralise HTML and markdown exfiltration vectors and redact PII. Returns the cleaned text (max 65 535 chars).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The untrusted text to sanitize (max 65 535 chars).')->max(self::MAX_TEXT_LENGTH)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $text = (string) $request->get('text', '');

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return Response::error(sprintf('The text field must not exceed %d characters.', self::MAX_TEXT_LENGTH));
        }

        return Response::json([
            'text' => AiGuardrails::sanitize($text),
        ]);
    }
}
