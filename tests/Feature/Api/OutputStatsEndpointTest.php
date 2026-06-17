<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use DateTimeImmutable;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use Padosoft\AiGuardrails\Tests\TestCase;

final class OutputStatsEndpointTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.output_stats.store', 'array');
    }

    public function test_returns_zero_filled_counts_for_every_kind(): void
    {
        $this->getJson('/ai-guardrails/api/output/stats')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.output-stats')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.counts.html_stripped', 0)
            ->assertJsonPath('data.counts.markdown_sanitized', 0)
            ->assertJsonPath('data.counts.structured_validation_failure', 0)
            ->assertJsonPath('data.counts.pii_redaction', 0);
    }

    public function test_reflects_recorded_events(): void
    {
        $store = $this->app->make(OutputStatStore::class);
        $store->record(OutputStatKind::HtmlStripped);
        $store->record(OutputStatKind::HtmlStripped);
        $store->record(OutputStatKind::PiiRedaction, 3);

        $this->getJson('/ai-guardrails/api/output/stats')
            ->assertOk()
            ->assertJsonPath('data.counts.html_stripped', 2)
            ->assertJsonPath('data.counts.pii_redaction', 3)
            ->assertJsonPath('data.counts.markdown_sanitized', 0)
            ->assertJsonPath('data.total', 5);
    }

    public function test_from_to_window_bounds_filter_the_totals(): void
    {
        $store = $this->app->make(OutputStatStore::class);
        $store->record(OutputStatKind::HtmlStripped); // recorded at "now"

        // A window entirely in the past must exclude the just-recorded event.
        $this->getJson('/ai-guardrails/api/output/stats?from=1999-01-01&to=2000-01-01')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.from', '1999-01-01T00:00:00+00:00')
            ->assertJsonPath('data.to', '2000-01-01T00:00:00+00:00');

        // An open-ended window starting in the past must include it.
        $this->getJson('/ai-guardrails/api/output/stats?from=2000-01-01')
            ->assertOk()
            ->assertJsonPath('data.counts.html_stripped', 1)
            ->assertJsonPath('data.total', 1);
    }

    public function test_defaults_to_a_bounded_30_day_window(): void
    {
        // With no bounds, the endpoint must NOT scan the whole log — it defaults to a 30-day window.
        $response = $this->getJson('/ai-guardrails/api/output/stats')->assertOk();

        $from = new DateTimeImmutable((string) $response->json('data.from'));
        $to = new DateTimeImmutable((string) $response->json('data.to'));

        self::assertSame(30, (int) $from->diff($to)->days, 'default window should span 30 days');
    }
}
