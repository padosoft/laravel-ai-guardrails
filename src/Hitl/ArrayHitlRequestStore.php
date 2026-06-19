<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Padosoft\AiGuardrails\Contracts\HitlRequestStore;

/**
 * In-memory append-only HITL request sidecar store (tests / config default).
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

        $map = [];
        // Iterate in order; last write wins (most-recent wins for duplicate run_id).
        foreach ($this->rows as $row) {
            if (in_array($row['run_id'], $runIds, true)) {
                $map[$row['run_id']] = [
                    'tool' => $row['tool'],
                    'arguments' => $row['arguments'],
                ];
            }
        }

        return $map;
    }
}
