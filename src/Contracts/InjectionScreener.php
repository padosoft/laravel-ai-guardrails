<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Screening\ScreenVerdict;

interface InjectionScreener
{
    public function screen(string $prompt): ScreenVerdict;
}
