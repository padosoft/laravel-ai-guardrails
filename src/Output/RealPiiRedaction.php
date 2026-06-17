<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\PiiRedactor\RedactorEngine;

/**
 * Adapter that composes padosoft/laravel-pii-redactor. Bound only when that package is installed
 * (the provider guards with class_exists); otherwise the package degrades to NullPiiRedaction.
 */
final readonly class RealPiiRedaction implements PiiRedaction
{
    public function __construct(private RedactorEngine $engine) {}

    public function redact(string $text): string
    {
        return $this->engine->isEnabled() ? $this->engine->redact($text) : $text;
    }
}
