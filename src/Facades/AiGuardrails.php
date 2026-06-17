<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\AiGuardrails\Screening\ScreenVerdict;

/**
 * @method static ScreenVerdict screen(string $prompt)
 * @method static string sanitize(string $text)
 * @method static \Laravel\Ai\Contracts\Tool guard(\Laravel\Ai\Contracts\Tool $tool, ?\Closure $principalResolver = null)
 * @method static \Laravel\Ai\Contracts\Tool routeForApproval(\Laravel\Ai\Contracts\Tool $tool, string $toolName, ?\Closure $principalResolver = null)
 * @method static bool isDestructive(string $toolName)
 * @method static array<string,string> validateStructured(array<string,mixed> $output, array<string,\Illuminate\JsonSchema\Types\Type> $schema, bool $rejectUnknown = false)
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
