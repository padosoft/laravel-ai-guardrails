<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

final class NullInjectionAuditStore implements InjectionAuditStore
{
    public function append(InjectionAttempt $attempt): void
    {
        // no-op
    }

    public function recent(int $limit = 50): array
    {
        return [];
    }

    public function query(AuditQueryFilters $filters): AuditPage
    {
        return new AuditPage([]);
    }

    public function find(int $id): ?InjectionAttempt
    {
        return null;
    }

    public function trend(DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        return [];
    }
}
