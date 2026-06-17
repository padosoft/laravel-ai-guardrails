<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

/**
 * In-memory append-only store (tests / default). Never mutates existing entries.
 */
final class ArrayInjectionAuditStore implements InjectionAuditStore
{
    /** @var list<InjectionAttempt> */
    private array $attempts = [];

    public function append(InjectionAttempt $attempt): void
    {
        $this->attempts[] = $attempt;
    }

    public function recent(int $limit = 50): array
    {
        return array_slice(array_reverse($this->attempts), 0, max(0, $limit));
    }
}
