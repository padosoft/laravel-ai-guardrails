<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Audit\InjectionAttempt;

interface InjectionAuditStore
{
    public function append(InjectionAttempt $attempt): void;

    /** @return list<InjectionAttempt> Most recent first. */
    public function recent(int $limit = 50): array;
}
