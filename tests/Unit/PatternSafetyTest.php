<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Screening\PatternInjectionScreener;
use Padosoft\AiGuardrails\Tests\TestCase;

final class PatternSafetyTest extends TestCase
{
    public function test_validate_patterns_detects_malformed_and_non_string(): void
    {
        $errors = PatternInjectionScreener::validatePatterns([
            'ok' => '/x/i',
            'bad' => '/unterminated',
            'not_string' => 123,
        ]);

        self::assertArrayNotHasKey('ok', $errors);
        self::assertArrayHasKey('bad', $errors);
        self::assertArrayHasKey('not_string', $errors);
    }

    public function test_ruleset_version_is_stamped_on_block_and_allow(): void
    {
        $screener = new PatternInjectionScreener(['r' => '/secret/i'], 'no', null, 0, 'v7');

        self::assertSame('v7', $screener->screen('the secret')->rulesetVersion);
        self::assertSame('v7', $screener->screen('benign prompt')->rulesetVersion);
    }

    public function test_on_match_error_open_skips_the_erroring_rule(): void
    {
        // /u pattern + bad-UTF-8 subject errors; 'open' skips the rule, so nothing matches → allow.
        $screener = new PatternInjectionScreener(['u' => '/x/u'], 'no', null, 0, 'v1', 'open');

        self::assertFalse($screener->screen("\xFF\xFE")->blocked);
    }

    public function test_on_match_error_closed_blocks_the_erroring_rule(): void
    {
        $screener = new PatternInjectionScreener(['u' => '/x/u'], 'no', null, 0, 'v1', 'closed');

        self::assertTrue($screener->screen("\xFF\xFE")->blocked);
    }
}
