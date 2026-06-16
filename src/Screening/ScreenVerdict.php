<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

final readonly class ScreenVerdict
{
    public function __construct(
        public bool $blocked,
        public ?string $ruleId = null,
        public ?string $refusalMessage = null,
    ) {}

    public static function allow(): self
    {
        return new self(false);
    }

    public static function block(string $ruleId, string $refusalMessage): self
    {
        return new self(true, $ruleId, $refusalMessage);
    }
}
