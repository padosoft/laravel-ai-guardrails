<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

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
            try {
                // class_exists is necessary but not sufficient: the engine may be unresolvable if
                // the pii-redactor service provider is not registered. Degrade rather than break.
                return new RealPiiRedaction($app->make(RedactorEngine::class));
            } catch (Throwable $e) {
                Log::warning('laravel-ai-guardrails: pii-redactor present but its engine could not be resolved; PII redaction disabled.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return new NullPiiRedaction;
    }
}
