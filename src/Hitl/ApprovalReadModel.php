<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Padosoft\AiGuardrails\Contracts\HitlRequestStore;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;

/**
 * Read side of Control D: lists the currently-pending HITL approvals from padosoft/laravel-flow's
 * `flow_approvals` table. Plain tokens are never persisted (only hashes), so the list exposes
 * approval/run ids — the operator approves/rejects by passing the token it already holds, and a host
 * dashboard can resolve full run details by `run_id`. Referenced only within the src/Hitl adapter
 * boundary; degrades to an empty list when flow is absent.
 *
 * Each pending item is enriched with tool + scoped arguments from the append-only sidecar store
 * (recorded at park-time by ApprovalGatedTool), plus relative-time strings for requested_ago and
 * expires_in.
 */
final class ApprovalReadModel
{
    public function __construct(
        private readonly ?HitlRequestStore $requestStore = null,
    ) {}

    /** @return list<array<string,mixed>> */
    public function pending(int $limit = 50): array
    {
        if (! class_exists(FlowApprovalRecord::class)) {
            return [];
        }

        try {
            // The flow_approvals table is shared: a host may run other (non-guardrails) flows through
            // laravel-flow. Scope to runs of THIS package's flow definition so we never expose
            // unrelated business approvals through the ai-guardrails API.
            $rows = FlowApprovalRecord::query()
                ->where('status', FlowApprovalRecord::STATUS_PENDING)
                ->whereIn('run_id', static function (Builder $query): void {
                    $query->select('id')
                        ->from((new FlowRunRecord)->getTable())
                        ->where('definition_name', FlowApprovalRouter::FLOW_NAME);
                })
                ->orderByDesc('created_at')
                ->limit(max(1, min(200, $limit)))
                // Only the exposed columns — never pull token_hash / payload / actor into memory.
                ->get(['id', 'run_id', 'step_name', 'status', 'expires_at', 'created_at']);
        } catch (\Throwable) {
            // Flow is installed but its tables aren't present (persistence off / not migrated). The
            // list degrades to empty rather than 500-ing the read-only endpoint.
            return [];
        }

        $pending = [];
        foreach ($rows as $row) {
            $pending[] = [
                'approval_id' => (string) $row->id,
                'run_id' => (string) $row->run_id,
                'step_name' => (string) $row->step_name,
                'status' => (string) $row->status,
                'expires_at' => $this->iso($row->expires_at),
                'created_at' => $this->iso($row->created_at),
            ];
        }

        return $this->enrich($pending);
    }

    /**
     * Count of pending approvals for this package's flow definition, without fetching or
     * enriching rows. Use this for the overview totals instead of count($this->pending()),
     * which is capped at 50 and would undercount a large approval backlog.
     */
    public function pendingCount(): int
    {
        if (! class_exists(FlowApprovalRecord::class)) {
            return 0;
        }

        try {
            return (int) FlowApprovalRecord::query()
                ->where('status', FlowApprovalRecord::STATUS_PENDING)
                ->whereIn('run_id', static function (Builder $query): void {
                    $query->select('id')
                        ->from((new FlowRunRecord)->getTable())
                        ->where('definition_name', FlowApprovalRouter::FLOW_NAME);
                })
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Test helper: enrich a pre-built set of pending rows (bypasses the flow query).
     * Only for use in tests where laravel-flow is not loaded.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    public function pendingWithStub(array $rows): array
    {
        return $this->enrich($rows);
    }

    /**
     * Merge sidecar data (tool, arguments) and relative-time strings into each pending item.
     *
     * @param  list<array<string,mixed>>  $pending
     * @return list<array<string,mixed>>
     */
    private function enrich(array $pending): array
    {
        if ($pending === []) {
            return [];
        }

        $runIds = array_values(array_filter(array_column($pending, 'run_id'), 'is_string'));
        $sidecar = [];
        if ($this->requestStore !== null && $runIds !== []) {
            try {
                $sidecar = $this->requestStore->forRunIds($runIds);
            } catch (\Throwable) {
                // Best-effort: sidecar table may not be migrated (hitl_requests.store=database but
                // migration not yet run) or the DB may be transiently unavailable. Degrade to an empty
                // sidecar map so each item falls back to tool='' / arguments={}, rather than 500-ing
                // the read-only /approvals endpoint. Mirrors the best-effort write path in ApprovalGatedTool.
                $sidecar = [];
            }
        }

        foreach ($pending as &$item) {
            $sid = $sidecar[(string) ($item['run_id'] ?? '')] ?? null;
            $item['tool'] = $sid['tool'] ?? '';
            $item['arguments'] = (object) ($sid['arguments'] ?? []);
            $item['requested_ago'] = $this->relative((string) ($item['created_at'] ?? ''));
            $expiresAt = $item['expires_at'] ?? null;
            $item['expires_in'] = $expiresAt !== null ? $this->relative((string) $expiresAt) : null;
        }
        unset($item);

        return $pending;
    }

    private function relative(string $isoString): string
    {
        try {
            $dt = new DateTimeImmutable($isoString, new DateTimeZone('UTC'));

            // Call diffForHumans() without an explicit operand so Carbon uses the real wall-clock
            // "now" as the reference point, yielding natural phrasing: "5 minutes ago" for past
            // timestamps (requested_ago) and "in 28 minutes" / "28 minutes from now" for future
            // timestamps (expires_in).  Passing $now explicitly produces "X minutes before/after"
            // phrasing, which is less readable for API consumers.
            return Carbon::instance($dt)->diffForHumans();
        } catch (\Throwable) {
            return '';
        }
    }

    private function iso(?\DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
