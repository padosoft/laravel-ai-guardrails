<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Audit\PromptHygiene;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use PHPUnit\Framework\TestCase;

final class PromptHygieneTest extends TestCase
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

    public function test_raw_keeps_the_prompt_verbatim(): void
    {
        $h = new PromptHygiene('raw', 2000, $this->pii());

        self::assertSame('contact john@example.com', $h->apply('contact john@example.com'));
        self::assertFalse($h->transformsContent());
    }

    public function test_redact_composes_pii_redaction(): void
    {
        $h = new PromptHygiene('redact', 2000, $this->pii());

        self::assertSame('contact [email]', $h->apply('contact john@example.com'));
        self::assertTrue($h->transformsContent());
    }

    public function test_unknown_mode_fails_safe_to_redact_never_raw(): void
    {
        $h = new PromptHygiene('nonsense', 2000, $this->pii());

        self::assertSame('contact [email]', $h->apply('contact john@example.com'));
        self::assertTrue($h->transformsContent());
    }

    public function test_hash_stores_only_a_sha256_digest(): void
    {
        $h = new PromptHygiene('hash', 2000, $this->pii());

        self::assertSame('sha256:'.hash('sha256', 'secret prompt'), $h->apply('secret prompt'));
        // Identical prompts hash identically (correlation preserved); different ones differ.
        self::assertSame($h->apply('a'), $h->apply('a'));
        self::assertNotSame($h->apply('a'), $h->apply('b'));
    }

    public function test_truncate_keeps_only_the_first_n_code_points(): void
    {
        $h = new PromptHygiene('truncate', 5, $this->pii());

        self::assertSame('hello', $h->apply('hello world'));
        // Multibyte-safe: counts code points, not bytes.
        self::assertSame('àéîõü', $h->apply('àéîõü extra'));
    }
}
