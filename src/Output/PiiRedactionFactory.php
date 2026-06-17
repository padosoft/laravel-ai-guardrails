<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Illuminate\Contracts\Foundation\Application;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\PiiRedactor\RedactorEngine;

/**
 * Chooses the PII redaction implementation at boot. Keeps the optional-vendor reference inside the
 * src/Output adapter boundary (the service provider must not reference padosoft/laravel-pii-redactor
 * directly). When the package is absent or PII redaction is disabled, degrades to NullPiiRedaction.
 */
final class PiiRedactionFactory
{
    public static function make(Application $app, bool $redactPii): PiiRedaction
    {
        if ($redactPii && class_exists(RedactorEngine::class)) {
            return new RealPiiRedaction($app->make(RedactorEngine::class));
        }

        return new NullPiiRedaction;
    }
}
