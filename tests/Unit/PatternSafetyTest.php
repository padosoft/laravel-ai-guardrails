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

    public function test_validate_patterns_is_syntax_only_and_does_not_catch_redos(): void
    {
        // A catastrophically backtracking pattern compiles fine → passes boot validation.
        // The runtime pcre_backtrack_limit is the only ReDoS guard.
        $errors = PatternInjectionScreener::validatePatterns([
            'redos' => '/(a+)+$/',
        ]);

        self::assertArrayNotHasKey('redos', $errors);
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

    public function test_on_match_error_open_records_errored_rule_ids_in_verdict(): void
    {
        // Errored rule IDs must appear in the verdict so the audit trail captures the bypass.
        $screener = new PatternInjectionScreener(['bad_utf' => '/x/u'], 'no', null, 0, 'v1', 'open');

        $verdict = $screener->screen("\xFF\xFE");

        self::assertFalse($verdict->blocked);
        self::assertContains('bad_utf', $verdict->erroredRuleIds);
    }

    public function test_backtrack_limit_closed_blocks_on_limit_error(): void
    {
        // /(a+)+$/ against a string of 'a's with no trailing match is the canonical catastrophic-
        // backtracking pattern. A tiny backtrackLimit forces PREG_BACKTRACK_LIMIT_ERROR.
        $screener = new PatternInjectionScreener(
            ['dos' => '/(a+)+$/'],
            'no',
            null,
            0,
            'v1',
            'closed',
            10, // tiny limit guarantees PREG_BACKTRACK_LIMIT_ERROR on a long input
        );

        $verdict = $screener->screen(str_repeat('a', 20).'X');

        self::assertTrue($verdict->blocked);
        self::assertStringStartsWith('pattern_error:', $verdict->ruleId ?? '');
    }

    public function test_backtrack_limit_open_allows_on_limit_error(): void
    {
        $screener = new PatternInjectionScreener(
            ['dos' => '/(a+)+$/'],
            'no',
            null,
            0,
            'v1',
            'open',
            10,
        );

        $verdict = $screener->screen(str_repeat('a', 20).'X');

        self::assertFalse($verdict->blocked);
        self::assertContains('dos', $verdict->erroredRuleIds);
    }

    public function test_backtrack_limit_open_still_matches_non_erroring_rules(): void
    {
        // When a rule errors under open mode, subsequent non-erroring rules are still evaluated.
        $screener = new PatternInjectionScreener(
            [
                'dos' => '/(a+)+$/',    // will error
                'injection' => '/secret/i', // should still match
            ],
            'no',
            null,
            0,
            'v1',
            'open',
            10,
        );

        $verdict = $screener->screen(str_repeat('a', 20).'X secret');

        self::assertTrue($verdict->blocked);
        self::assertSame('injection', $verdict->ruleId);
    }

    public function test_backtrack_limit_is_restored_after_screen(): void
    {
        $original = ini_get('pcre.backtrack_limit');

        $screener = new PatternInjectionScreener(
            ['p' => '/x/'],
            'no',
            null,
            0,
            'v1',
            'closed',
            42, // arbitrary non-default limit
        );

        $screener->screen('benign');

        self::assertSame($original, ini_get('pcre.backtrack_limit'));
    }
}
