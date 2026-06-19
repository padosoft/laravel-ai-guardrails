<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Padosoft\AiGuardrails\Contracts\HitlRequestStore;

final class NullHitlRequestStore implements HitlRequestStore
{
    public function record(string $runId, ?string $approvalId, string $tool, array $arguments, int|string|null $principalId): void
    {
        // no-op
    }

    public function forRunIds(array $runIds): array
    {
        return [];
    }
}
