<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\AiGuardrails\Screening\ScreenVerdict;

/**
 * @method static ScreenVerdict screen(string $prompt)
 * @method static string sanitize(string $text)
 *
 * @see \Padosoft\AiGuardrails\AiGuardrails
 */
final class AiGuardrails extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ai-guardrails';
    }
}
