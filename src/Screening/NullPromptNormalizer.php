<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Padosoft\AiGuardrails\Contracts\PromptNormalizer;

final class NullPromptNormalizer implements PromptNormalizer
{
    public function normalize(string $prompt): string
    {
        return $prompt;
    }
}
