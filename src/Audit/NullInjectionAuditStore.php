<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

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
}
