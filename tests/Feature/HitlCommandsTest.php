<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Padosoft\AiGuardrails\Tests\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;

/**
 * L4 — HITL turnkey commands. laravel-flow is a require-dev dependency, so it is always "installed"
 * here; the both-states coverage is on the flow tables + the hitl/master toggles.
 */
final class HitlCommandsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LaravelFlowServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    private function migrateFlow(): void
    {
        $files = glob(__DIR__.'/../../vendor/padosoft/laravel-flow/database/migrations/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            (require $file)->up();
        }
    }

    public function test_status_reports_success_when_fully_configured(): void
    {
        $this->migrateFlow();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('ai-guardrails.enabled', true);
        $this->app['config']->set('ai-guardrails.hitl.enabled', true);

        $this->artisan('ai-guardrails:hitl-status')
            ->expectsOutputToContain('fully configured')
            ->assertSuccessful();
    }

    public function test_status_fails_when_flow_tables_are_missing(): void
    {
        // No flow migrations run → flow_runs/flow_approvals absent.
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('ai-guardrails.hitl.enabled', true);

        $this->artisan('ai-guardrails:hitl-status')
            ->expectsOutputToContain('ai-guardrails:hitl-install')
            ->assertFailed();
    }

    public function test_status_fails_when_hitl_disabled(): void
    {
        $this->migrateFlow();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('ai-guardrails.hitl.enabled', false);

        $this->artisan('ai-guardrails:hitl-status')
            ->expectsOutputToContain('AI_GUARDRAILS_HITL_ENABLED=true')
            ->assertFailed();
    }

    public function test_install_publishes_and_migrates_the_flow_tables(): void
    {
        // Starting clean (no flow tables): install publishes the flow migrations and runs them.
        self::assertFalse(Schema::hasTable('flow_runs'));

        $this->artisan('ai-guardrails:hitl-install')
            ->expectsOutputToContain('HITL tables are ready')
            ->assertSuccessful();

        self::assertTrue(Schema::hasTable('flow_runs'));
        self::assertTrue(Schema::hasTable('flow_approvals'));
    }
}
