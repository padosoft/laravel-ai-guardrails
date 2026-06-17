<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

final readonly class ScreenVerdict
{
    /** @param list<string> $erroredRuleIds Rule IDs that errored (preg_match returned false) and were skipped under on_match_error=open. */
    private function __construct(
        public bool $blocked,
        public ?string $ruleId,
        public ?string $refusalMessage,
        public ?string $rulesetVersion = null,
        public array $erroredRuleIds = [],
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
        return new self($this->blocked, $this->ruleId, $this->refusalMessage, $rulesetVersion, $this->erroredRuleIds);
    }

    /**
     * Record rule IDs that errored under on_match_error=open so the bypass is forensically visible
     * in the audit trail even when no rule matched.
     *
     * @param  list<string>  $ruleIds
     */
    public function withErroredRuleIds(array $ruleIds): self
    {
        return new self($this->blocked, $this->ruleId, $this->refusalMessage, $this->rulesetVersion, $ruleIds);
    }
}
