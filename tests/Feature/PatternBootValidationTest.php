<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Exceptions\InvalidScreeningPattern;
use Padosoft\AiGuardrails\Tests\TestCase;

final class PatternBootValidationTest extends TestCase
{
    public function test_boot_throws_on_a_malformed_pattern(): void
    {
        $this->app['config']->set('ai-guardrails.input_screen.patterns', ['bad' => '/unterminated']);
        $this->app['config']->set('ai-guardrails.pattern_safety.validate_at_boot', true);

        $this->expectException(InvalidScreeningPattern::class);

        (new AiGuardrailsServiceProvider($this->app))->boot();
    }

    public function test_boot_is_fine_with_valid_patterns(): void
    {
        $this->app['config']->set('ai-guardrails.input_screen.patterns', ['ok' => '/ignore previous/iu']);

        (new AiGuardrailsServiceProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }

    public function test_boot_validation_can_be_disabled(): void
    {
        $this->app['config']->set('ai-guardrails.input_screen.patterns', ['bad' => '/unterminated']);
        $this->app['config']->set('ai-guardrails.pattern_safety.validate_at_boot', false);

        // Should NOT throw when validation is disabled.
        (new AiGuardrailsServiceProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }
}
