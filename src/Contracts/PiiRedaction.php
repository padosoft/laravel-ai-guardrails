<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

interface PiiRedaction
{
    public function redact(string $text): string;
}
