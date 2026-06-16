<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Screening\ScreenVerdict;

final readonly class AiGuardrails
{
    public function __construct(
        private InjectionScreener $screener,
        private OutputSanitizer $sanitizer,
        private PiiRedaction $pii,
    ) {}

    public function screen(string $prompt): ScreenVerdict
    {
        return $this->screener->screen($prompt);
    }

    public function sanitize(string $text): string
    {
        return $this->pii->redact($this->sanitizer->sanitize($text));
    }
}
