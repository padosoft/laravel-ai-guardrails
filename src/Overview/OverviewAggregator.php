<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Overview;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Hitl\ApprovalReadModel;
use Padosoft\AiGuardrails\Support\ControlMode;
use Padosoft\AiGuardrails\Support\ResolvesControlMode;

/**
 * Aggregates control health + recent counts for the admin overview screen (GET /overview). Reads
 * the injection audit store; firewall/output/approval counts light up as those stores land.
 */
final readonly class OverviewAggregator
{
    public function __construct(
        private InjectionAuditStore $audit,
        private Config $config,
        private ApprovalReadModel $approvalReadModel,
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
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff24h = $now->modify('-24 hours');

        $in24h = array_filter($recent, static fn ($a): bool => $a->occurredAt >= $cutoff24h);
        $blocked24h = array_filter($in24h, static fn ($a): bool => $a->blocked);
        // observed: rule matched (ruleId set) but NOT blocked — shadow-rollout monitor hits
        $observed24h = array_filter($in24h, static fn ($a): bool => $a->ruleId !== null && ! $a->blocked);

        // Fetch the recent 12h window for sparkline buckets (hourly; most recent last).
        // Use strictly-greater-than so a row at exactly T-12h is excluded: the bucket formula
        // gives hoursAgo=12 → bucketIndex=-1 (out of range), so the pre-filter and bucket
        // assignment must agree on the boundary.
        $cutoff12h = $now->modify('-12 hours');
        $in12h = array_filter($recent, static fn ($a): bool => $a->occurredAt > $cutoff12h);

        // NOTE: the audit store does not attribute attempts to a specific control, so every
        // control shares the same trailing-12h sparkline (derived from all injection attempts).
        return [
            'controls' => [
                $this->control('tool_firewall', 'Tool Firewall', $in12h, $now),
                $this->control('input_screen', 'Input Screening', $in12h, $now),
                $this->control('output_handler', 'Output Handler', $in12h, $now),
                $this->control('hitl', 'HITL Bridge', $in12h, $now),
            ],
            'totals' => [
                'attempts_24h' => count($in24h),
                'blocked_24h' => count($blocked24h),
                // True when the recent window was saturated, so the 24h counts may undercount;
                // the admin should defer to /audit/trend for exact figures.
                'sampled' => count($recent) >= $sampleSize,
                // v1.1 additions — backward-compatible new keys
                'observed_24h' => count($observed24h),
                'pending_approvals' => $this->approvalReadModel->pendingCount(),
            ],
            // E9-API delta: the active screening ruleset version, so the admin can correlate audit
            // rows (which carry their own ruleset_version) with what is live now.
            'ruleset_version' => (string) $this->config->get('ai-guardrails.pattern_safety.ruleset_version', 'v1'),
        ];
    }

    /**
     * @param  InjectionAttempt[]  $in12h  Attempts within the trailing 12-hour window (pre-filtered).
     * @return array<string,mixed>
     */
    private function control(string $key, string $label, array $in12h, DateTimeImmutable $now): array
    {
        $masterOn = (bool) $this->config->get('ai-guardrails.enabled', true);
        $controlOn = (bool) $this->config->get("ai-guardrails.{$key}.enabled", $key !== 'hitl');
        $enabled = $masterOn && $controlOn;

        // E9-API delta: the resolved enforcement posture (enforce | monitor | off) for shadow-rollout
        // visibility. Short-circuit to Off when already disabled (avoids the default-true fallback
        // inside ResolvesControlMode, which would produce mode='enforce' for HITL when its
        // enabled key is absent — HITL defaults off, unlike the other three controls).
        $mode = $enabled
            ? ResolvesControlMode::for($key, "ai-guardrails.{$key}.enabled")
            : ControlMode::Off;

        return [
            'key' => $key,
            'label' => $label,
            'enabled' => $enabled,
            'mode' => $mode->value,
            // v1.1 additions
            'posture' => $this->posture($mode),
            'spark' => $this->spark($in12h, $now),
        ];
    }

    /**
     * Human-readable label for the control's enforcement posture.
     */
    private function posture(ControlMode $mode): string
    {
        return match ($mode) {
            ControlMode::Enforce => 'Engaged',
            ControlMode::Monitor => 'Observing',
            ControlMode::Off => 'Disabled',
        };
    }

    /**
     * 12 trailing-hour attempt counts, most-recent last (index 11 = current hour).
     * Bucketed in PHP from the pre-fetched 12h window.
     *
     * @param  InjectionAttempt[]  $in12h
     * @return list<int>
     */
    private function spark(array $in12h, DateTimeImmutable $now): array
    {
        // Initialise 12 zero-buckets: bucket 0 = 12 hours ago, bucket 11 = most-recent hour.
        /** @var array<int,int> $buckets */
        $buckets = array_fill(0, 12, 0);

        foreach ($in12h as $attempt) {
            // How many complete seconds ago did this happen?
            $secondsAgo = $now->getTimestamp() - $attempt->occurredAt->getTimestamp();
            // Which hour-bucket (0=oldest, 11=newest)?
            $hoursAgo = (int) floor($secondsAgo / 3600);
            $bucketIndex = 11 - $hoursAgo;

            if ($bucketIndex >= 0 && $bucketIndex <= 11) {
                $buckets[$bucketIndex]++;
            }
        }

        return array_values($buckets);
    }
}
