<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Padosoft\AiGuardrails\Contracts\PiiRedaction;

final class NullPiiRedaction implements PiiRedaction
{
    public function redact(string $text): string
    {
        return $text;
    }
}
