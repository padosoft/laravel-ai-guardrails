<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

interface OutputSanitizer
{
    public function sanitize(string $text): string;
}
