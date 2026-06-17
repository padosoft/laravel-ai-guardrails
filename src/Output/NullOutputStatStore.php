<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;

final class NullOutputStatStore implements OutputStatStore
{
    public function record(OutputStatKind $kind, int $count = 1): void
    {
        // no-op
    }

    public function totals(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        return [];
    }

    public function count(): int
    {
        return 0;
    }
}
