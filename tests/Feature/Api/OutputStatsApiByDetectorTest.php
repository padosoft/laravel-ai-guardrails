<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Feature tests for pii.by_detector in GET /ai-guardrails/api/output/stats.
 *
 * Available branch: the store carries a nullable detector field; the controller
 * surfaces data.counts.pii.by_detector = array<string,int>.
 */
final class OutputStatsApiByDetectorTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.output_stats.store', 'array');
    }

    // ── Available-branch: by_detector map is populated when detector rows exist ──

    public function test_by_detector_reflects_per_detector_pii_counts(): void
    {
        $store = $this->app->make(OutputStatStore::class);

        // Simulate the middleware recording per-detector: email×2, phone×1
        $store->record(OutputStatKind::PiiRedaction, 1, 'email');
        $store->record(OutputStatKind::PiiRedaction, 1, 'email');
        $store->record(OutputStatKind::PiiRedaction, 1, 'phone');

        $this->getJson('/ai-guardrails/api/output/stats')
            ->assertOk()
            ->assertJsonPath('data.counts.pii_redaction', 3)
            ->assertJsonPath('data.counts.pii.by_detector', ['email' => 2, 'phone' => 1])
            ->assertJsonPath('data.total', 3);
    }

    public function test_by_detector_is_empty_object_when_no_pii_rows(): void
    {
        // No records at all → pii.by_detector must be {} (empty JSON object)
        $this->getJson('/ai-guardrails/api/output/stats')
            ->assertOk()
            ->assertJsonPath('data.counts.pii.by_detector', []);
    }

    public function test_by_detector_is_empty_when_pii_rows_have_no_detector(): void
    {
        // Old-style rows with no detector (null) should not break by_detector (stays empty)
        $store = $this->app->make(OutputStatStore::class);
        $store->record(OutputStatKind::PiiRedaction, 2); // no detector

        $this->getJson('/ai-guardrails/api/output/stats')
            ->assertOk()
            ->assertJsonPath('data.counts.pii_redaction', 2)
            ->assertJsonPath('data.counts.pii.by_detector', [])
            ->assertJsonPath('data.total', 2);
    }

    // ── Backward-compat: existing counts.* keys and total are unchanged ──────────

    public function test_existing_counts_keys_are_still_present_and_correct(): void
    {
        $store = $this->app->make(OutputStatStore::class);
        $store->record(OutputStatKind::HtmlStripped);
        $store->record(OutputStatKind::MarkdownSanitized, 2);
        $store->record(OutputStatKind::PiiRedaction, 1, 'email');

        $this->getJson('/ai-guardrails/api/output/stats')
            ->assertOk()
            ->assertJsonPath('data.counts.html_stripped', 1)
            ->assertJsonPath('data.counts.markdown_sanitized', 2)
            ->assertJsonPath('data.counts.structured_validation_failure', 0)
            ->assertJsonPath('data.counts.pii_redaction', 1)
            ->assertJsonPath('data.total', 4);
    }

    // ── Mixed: detector + no-detector rows ───────────────────────────────────────

    public function test_pii_redaction_total_includes_both_detector_and_null_rows(): void
    {
        $store = $this->app->make(OutputStatStore::class);
        $store->record(OutputStatKind::PiiRedaction, 3, 'email');
        $store->record(OutputStatKind::PiiRedaction, 2);       // legacy null detector

        $this->getJson('/ai-guardrails/api/output/stats')
            ->assertOk()
            ->assertJsonPath('data.counts.pii_redaction', 5)
            ->assertJsonPath('data.counts.pii.by_detector', ['email' => 3])
            ->assertJsonPath('data.total', 5);
    }
}
