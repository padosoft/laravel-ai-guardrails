<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Padosoft\AiGuardrails\Contracts\InjectionScreener;

final class NullInjectionScreener implements InjectionScreener
{
    public function screen(string $prompt): ScreenVerdict
    {
        return ScreenVerdict::allow();
    }
}
