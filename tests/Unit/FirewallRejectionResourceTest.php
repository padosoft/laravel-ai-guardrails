<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\AiGuardrails\Firewall\FirewallRejection;
use Padosoft\AiGuardrails\Http\Resources\FirewallRejectionResource;
use PHPUnit\Framework\TestCase;

final class FirewallRejectionResourceTest extends TestCase
{
    private function rejection(
        string $tool = 'test tool',
        array $violations = ['field' => 'reason'],
    ): FirewallRejection {
        return new FirewallRejection(
            $tool,
            'u1',
            $violations,
            new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
            id: 1,
        );
    }

    public function test_violation_values_are_bounded_at_500_chars(): void
    {
        $longReason = str_repeat('x', 600);
        $result = FirewallRejectionResource::summary($this->rejection(violations: ['field' => $longReason]));

        $value = $result['violations']['field'];
        // 500 chars + the ellipsis character (…)
        self::assertSame(501, mb_strlen($value, 'UTF-8'));
        self::assertStringEndsWith('…', $value);
    }

    public function test_tool_description_is_bounded_at_200_chars(): void
    {
        $result = FirewallRejectionResource::summary($this->rejection(tool: str_repeat('t', 300)));

        self::assertSame(201, mb_strlen($result['tool'], 'UTF-8'));
        self::assertStringEndsWith('…', $result['tool']);
    }

    public function test_violation_keys_are_utf8_scrubbed(): void
    {
        // Simulate a violation key that contains an invalid UTF-8 byte sequence.
        $badKey = "field\x80name"; // \x80 is invalid UTF-8
        $result = FirewallRejectionResource::summary($this->rejection(violations: [$badKey => 'reason']));

        foreach (array_keys($result['violations']) as $key) {
            self::assertTrue(mb_check_encoding($key, 'UTF-8'), "Key '{$key}' is not valid UTF-8");
        }
    }

    public function test_violation_values_are_utf8_scrubbed(): void
    {
        $badValue = "bad\x80value";
        $result = FirewallRejectionResource::summary($this->rejection(violations: ['field' => $badValue]));

        self::assertTrue(mb_check_encoding($result['violations']['field'], 'UTF-8'));
    }

    public function test_short_violations_are_not_truncated(): void
    {
        $result = FirewallRejectionResource::summary($this->rejection(violations: ['field' => 'short reason']));

        self::assertSame('short reason', $result['violations']['field']);
    }
}
