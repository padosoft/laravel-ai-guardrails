<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;

/**
 * Read side of Control D: lists the currently-pending HITL approvals from padosoft/laravel-flow's
 * `flow_approvals` table. Plain tokens are never persisted (only hashes), so the list exposes
 * approval/run ids — the operator approves/rejects by passing the token it already holds, and a host
 * dashboard can resolve full run details by `run_id`. Referenced only within the src/Hitl adapter
 * boundary; degrades to an empty list when flow is absent.
 */
final class ApprovalReadModel
{
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
                ->get();
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

        return $pending;
    }

    private function iso(?\DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
