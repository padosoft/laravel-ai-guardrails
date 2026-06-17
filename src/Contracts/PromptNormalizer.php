<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

interface PromptNormalizer
{
    /**
     * Normalize a prompt before screening so trivial evasions (unicode homoglyphs, zero-width
     * characters, case folding) cannot slip an injection past the pattern matcher.
     */
    public function normalize(string $prompt): string;
}
