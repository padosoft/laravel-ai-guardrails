<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

/**
 * Decorator that applies prompt hygiene (Task E5) on the WRITE path of any inner audit store, so the
 * append-only table never receives a raw prompt when `audit_hygiene.prompt_storage` is redact/hash/
 * truncate. Read methods delegate untouched. When hygiene changes the prompt, the byte-offset
 * matched-span is dropped (it no longer aligns with the transformed text).
 */
final readonly class HygienicInjectionAuditStore implements InjectionAuditStore
{
    public function __construct(
        private InjectionAuditStore $inner,
        private PromptHygiene $hygiene,
    ) {}

    public function append(InjectionAttempt $attempt): void
    {
        $clean = $this->hygiene->apply($attempt->prompt);

        // No transformation (raw mode, or redact left it unchanged) → persist as-is, span preserved.
        if ($clean === $attempt->prompt) {
            $this->inner->append($attempt);

            return;
        }

        $this->inner->append(new InjectionAttempt(
            $clean,
            $attempt->blocked,
            $attempt->ruleId,
            $attempt->principalId,
            $attempt->occurredAt,
            $attempt->rulesetVersion,
            $attempt->erroredRuleIds,
            // The matched span is a byte offset into the ORIGINAL prompt — meaningless once the stored
            // prompt is redacted/hashed/truncated, so it is intentionally dropped.
            null,
            $attempt->id,
        ));
    }

    public function recent(int $limit = 50): array
    {
        return $this->inner->recent($limit);
    }

    public function query(AuditQueryFilters $filters): AuditPage
    {
        return $this->inner->query($filters);
    }

    public function find(int $id): ?InjectionAttempt
    {
        return $this->inner->find($id);
    }

    public function trend(DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        return $this->inner->trend($since, $until);
    }
}
