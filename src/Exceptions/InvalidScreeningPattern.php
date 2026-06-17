<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Exceptions;

use RuntimeException;

final class InvalidScreeningPattern extends RuntimeException
{
    /** @param array<string,string> $errors ruleId => PCRE error message */
    public function __construct(public readonly array $errors)
    {
        $summary = implode('; ', array_map(
            static fn (string $ruleId, string $message): string => "[{$ruleId}] {$message}",
            array_keys($errors),
            array_values($errors),
        ));

        parent::__construct("Invalid screening pattern(s): {$summary}");
    }
}
