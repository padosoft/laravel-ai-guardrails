<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

interface HitlRequestStore
{
    /**
     * Append a sidecar row recording the tool + scoped arguments at park-time.
     *
     * @param  array<string,mixed>  $arguments
     */
    public function record(
        string $runId,
        ?string $approvalId,
        string $tool,
        array $arguments,
        int|string|null $principalId,
    ): void;

    /**
     * Return a map of run_id => ['tool' => string, 'arguments' => array] for the given run IDs.
     * Most-recent row wins when duplicate run_ids exist. Missing run_ids are absent from the map.
     *
     * @param  list<string>  $runIds
     * @return array<string, array{tool: string, arguments: array<string,mixed>}>
     */
    public function forRunIds(array $runIds): array;
}
