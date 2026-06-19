<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Padosoft\AiGuardrails\Contracts\HitlRequestStore;

/**
 * In-memory append-only HITL request sidecar store.
 *
 * Used for tests and when `hitl_requests.store=array` is configured explicitly.
 * This is NOT the package default — `hitl_requests.store` defaults to `'null'`
 * (the NullHitlRequestStore), which silently discards all writes.
 */
final class ArrayHitlRequestStore implements HitlRequestStore
{
    /** @var list<array{run_id:string,tool:string,arguments:array<string,mixed>,principal_id:int|string|null}> */
    private array $rows = [];

    public function record(string $runId, string $tool, array $arguments, int|string|null $principalId): void
    {
        $this->rows[] = [
            'run_id' => $runId,
            'tool' => $tool,
            'arguments' => $arguments,
            'principal_id' => $principalId,
        ];
    }

    public function forRunIds(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        // Build a lookup set once — O(runIds) — so the inner loop is O(1) per row
        // instead of O(runIds) per row, giving O(rows + runIds) overall.
        $wanted = array_flip($runIds);

        $map = [];
        // Iterate in insertion order; last write wins (most-recent wins for duplicate run_id).
        foreach ($this->rows as $row) {
            if (isset($wanted[$row['run_id']])) {
                $map[$row['run_id']] = [
                    'tool' => $row['tool'],
                    'arguments' => $row['arguments'],
                ];
            }
        }

        return $map;
    }
}
