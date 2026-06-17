<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Console;

use Illuminate\Console\Command;
use Padosoft\AiGuardrails\AiGuardrails;

final class GuardrailsSanitizeCommand extends Command
{
    protected $signature = 'ai-guardrails:sanitize {text? : The text to sanitize (reads STDIN if omitted)}';

    protected $description = 'Sanitize + redact an untrusted text blob (HTML/markdown + PII) and print the result.';

    public function handle(AiGuardrails $guardrails): int
    {
        $arg = $this->argument('text');
        $text = is_string($arg) && $arg !== '' ? $arg : trim((string) file_get_contents('php://stdin'));

        $this->line($guardrails->sanitize($text));

        return self::SUCCESS;
    }
}
