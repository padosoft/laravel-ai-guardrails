<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Overview;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

/**
 * Aggregates control health + recent counts for the admin overview screen (GET /overview). Reads
 * the injection audit store; firewall/output/approval counts light up as those stores land.
 */
final readonly class OverviewAggregator
{
    public function __construct(
        private InjectionAuditStore $audit,
        private Config $config,
    ) {}

    /**
     * A fast dashboard snapshot. The 24h totals are computed from the most recent window of audit
     * rows (an approximation, flagged via `sampled`); the authoritative daily counts come from the
     * SQL-aggregated trend endpoint (GET /audit/trend).
     *
     * @return array<string,mixed>
     */
    public function aggregate(): array
    {
        $sampleSize = 1000;
        $recent = $this->audit->recent($sampleSize);
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-24 hours');

        $in24h = array_filter($recent, static fn ($a): bool => $a->occurredAt >= $cutoff);
        $blocked24h = array_filter($in24h, static fn ($a): bool => $a->blocked);

        return [
            'controls' => [
                $this->control('tool_firewall', 'Tool Firewall'),
                $this->control('input_screen', 'Input Screening'),
                $this->control('output_handler', 'Output Handler'),
                $this->control('hitl', 'HITL Bridge'),
            ],
            'totals' => [
                'attempts_24h' => count($in24h),
                'blocked_24h' => count($blocked24h),
                // True when the recent window was saturated, so the 24h counts may undercount;
                // the admin should defer to /audit/trend for exact figures.
                'sampled' => count($recent) >= $sampleSize,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function control(string $key, string $label): array
    {
        $masterOn = (bool) $this->config->get('ai-guardrails.enabled', true);
        $controlOn = (bool) $this->config->get("ai-guardrails.{$key}.enabled", $key !== 'hitl');

        return [
            'key' => $key,
            'label' => $label,
            'enabled' => $masterOn && $controlOn,
        ];
    }
}
