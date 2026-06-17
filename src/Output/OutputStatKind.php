<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

/**
 * The kinds of output-handling events counted by the OutputStatStore (Control C), surfaced by
 * GET /output/stats so operators can see how often the model output had to be neutralised.
 */
enum OutputStatKind: string
{
    case HtmlStripped = 'html_stripped';
    case MarkdownSanitized = 'markdown_sanitized';
    case StructuredValidationFailure = 'structured_validation_failure';
    case PiiRedaction = 'pii_redaction';

    /** @return list<string> All kind values, for zero-filled totals. */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
