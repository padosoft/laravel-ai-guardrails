<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Padosoft\AiGuardrails\Contracts\OutputSanitizer;

final class PassthroughSanitizer implements OutputSanitizer
{
    public function sanitize(string $text): string
    {
        return $text;
    }
}
