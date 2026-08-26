<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\AiGuardrails;
use Padosoft\AiGuardrails\Contracts\GroundingProvenance;
use Padosoft\AiGuardrails\Provenance\NullGroundingProvenance;
use Padosoft\AiGuardrails\Provenance\ProvenanceGatedTool;
use Padosoft\AiGuardrails\Provenance\ProvenanceTier;
use Padosoft\AiGuardrails\Provenance\RequestGroundingProvenance;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * How the control reaches a tool, and — more importantly — how it stays out
 * of the way when nobody asked for it.
 */
final class ProvenanceWiringTest extends TestCase
{
    public function test_the_control_is_off_by_default(): void
    {
        // House convention, and the reason 551 pre-existing tests still pass
        // unchanged: a new security control that alters behaviour on upgrade
        // is a new security control nobody upgrades into.
        $this->assertFalse(config('ai-guardrails.provenance.enabled'));

        $guarded = app(AiGuardrails::class)->guard(new FakeDestructiveTool);

        $this->assertNotInstanceOf(ProvenanceGatedTool::class, $guarded);
    }

    public function test_the_default_binding_reports_nothing(): void
    {
        $this->assertInstanceOf(NullGroundingProvenance::class, app(GroundingProvenance::class));
        $this->assertSame([], app(GroundingProvenance::class)->tiers());
    }

    public function test_a_host_binding_replaces_the_default(): void
    {
        $this->app->singleton(GroundingProvenance::class, function () {
            $p = new RequestGroundingProvenance;
            $p->record(ProvenanceTier::UntrustedExternal);

            return $p;
        });

        $this->assertSame(
            [ProvenanceTier::UntrustedExternal],
            app(GroundingProvenance::class)->tiers(),
        );
    }

    public function test_enabling_it_wraps_the_tool(): void
    {
        config()->set('ai-guardrails.provenance.enabled', true);
        $this->app->forgetInstance(AiGuardrails::class);

        $this->assertInstanceOf(
            ProvenanceGatedTool::class,
            app(AiGuardrails::class)->guard(new FakeDestructiveTool),
        );
    }

    public function test_an_unrecognised_gating_tier_is_dropped_rather_than_fatal(): void
    {
        // A typo in one tier name must not take the application down at
        // boot. It DOES silently narrow the gate, which is why a list that
        // parses to nothing leaves the control inert rather than gating on
        // some invented default: plainly doing nothing beats confidently
        // gating on a tier nobody configured.
        config()->set('ai-guardrails.provenance.enabled', true);
        config()->set('ai-guardrails.provenance.gating_tiers', ['untrusted_externl', 42, null]);
        $this->app->forgetInstance(AiGuardrails::class);

        $this->assertNotInstanceOf(
            ProvenanceGatedTool::class,
            app(AiGuardrails::class)->guard(new FakeDestructiveTool),
        );
    }

    public function test_a_valid_tier_survives_alongside_a_typo(): void
    {
        config()->set('ai-guardrails.provenance.enabled', true);
        config()->set('ai-guardrails.provenance.gating_tiers', ['untrusted_externl', 'untrusted_external']);
        $this->app->forgetInstance(AiGuardrails::class);

        $this->assertInstanceOf(
            ProvenanceGatedTool::class,
            app(AiGuardrails::class)->guard(new FakeDestructiveTool),
        );
    }
}
