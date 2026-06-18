<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Audit\ArrayInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\HygienicInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Audit\PromptHygiene;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Tests\TestCase;

final class HygienicInjectionAuditStoreTest extends TestCase
{
    private function pii(): PiiRedaction
    {
        return new class implements PiiRedaction
        {
            public function redact(string $text): string
            {
                return str_replace('john@example.com', '[email]', $text);
            }
        };
    }

    private function attempt(string $prompt): InjectionAttempt
    {
        return new InjectionAttempt($prompt, true, 'rule', '42', new DateTimeImmutable('2026-01-01 10:00:00'), 'v1', [], [0, 4]);
    }

    public function test_redact_transforms_the_stored_prompt_and_drops_the_span(): void
    {
        $inner = new ArrayInjectionAuditStore;
        $store = new HygienicInjectionAuditStore($inner, new PromptHygiene('redact', 2000, $this->pii()));

        $store->append($this->attempt('mail john@example.com now'));

        $recent = $inner->recent();
        self::assertSame('mail [email] now', $recent[0]->prompt);
        // The byte-offset span no longer aligns with the transformed prompt → dropped.
        self::assertNull($recent[0]->matchedSpan);
        // Non-content fields are preserved.
        self::assertTrue($recent[0]->blocked);
        self::assertSame('rule', $recent[0]->ruleId);
        self::assertSame('42', $recent[0]->principalId);
    }

    public function test_raw_passes_through_untouched_keeping_the_span(): void
    {
        $inner = new ArrayInjectionAuditStore;
        $store = new HygienicInjectionAuditStore($inner, new PromptHygiene('raw', 2000, $this->pii()));

        $store->append($this->attempt('mail john@example.com now'));

        $recent = $inner->recent();
        self::assertSame('mail john@example.com now', $recent[0]->prompt);
        self::assertSame([0, 4], $recent[0]->matchedSpan); // preserved
    }

    public function test_read_methods_delegate_to_the_inner_store(): void
    {
        $inner = new ArrayInjectionAuditStore;
        $store = new HygienicInjectionAuditStore($inner, new PromptHygiene('hash', 2000, $this->pii()));

        $store->append($this->attempt('secret'));

        // recent() reflects the hashed prompt written through the decorator.
        self::assertSame('sha256:'.hash('sha256', 'secret'), $store->recent()[0]->prompt);
        self::assertCount(1, $store->recent());
    }
}
