<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

final readonly class ScreenVerdict
{
    private function __construct(
        public bool $blocked,
        public ?string $ruleId,
        public ?string $refusalMessage,
    ) {}

    public static function allow(): self
    {
        return new self(false, null, null);
    }

    public static function block(string $ruleId, string $refusalMessage): self
    {
        return new self(true, $ruleId, $refusalMessage);
    }
}
