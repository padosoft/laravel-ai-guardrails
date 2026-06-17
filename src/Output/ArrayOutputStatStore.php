<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;

/**
 * In-memory append-only output-stat store (tests / default). Each record is one immutable event row.
 */
final class ArrayOutputStatStore implements OutputStatStore
{
    /** @var list<array{kind:string,count:int,occurredAt:DateTimeImmutable}> */
    private array $events = [];

    public function record(OutputStatKind $kind, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $this->events[] = [
            'kind' => $kind->value,
            'count' => $count,
            'occurredAt' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ];
    }

    public function totals(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $totals = [];
        foreach ($this->events as $event) {
            if ($from !== null && $event['occurredAt'] < $from) {
                continue;
            }
            if ($to !== null && $event['occurredAt'] > $to) {
                continue;
            }
            $totals[$event['kind']] = ($totals[$event['kind']] ?? 0) + $event['count'];
        }

        return $totals;
    }

    public function count(): int
    {
        return array_sum(array_column($this->events, 'count'));
    }
}
