<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

final readonly class ScreenVerdict
{
    private function __construct(
        public bool $blocked,
        public ?string $ruleId,
        public ?string $refusalMessage,
        public ?string $rulesetVersion = null,
    ) {}

    public static function allow(): self
    {
        return new self(false, null, null);
    }

    public static function block(string $ruleId, string $refusalMessage): self
    {
        return new self(true, $ruleId, $refusalMessage);
    }

    /**
     * Stamp the matching ruleset version for forensic reproducibility (Task E2).
     */
    public function withRulesetVersion(string $rulesetVersion): self
    {
        return new self($this->blocked, $this->ruleId, $this->refusalMessage, $rulesetVersion);
    }
}
