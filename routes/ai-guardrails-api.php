<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\Registrar;
use Padosoft\AiGuardrails\Http\ApprovalsController;
use Padosoft\AiGuardrails\Http\AuditController;
use Padosoft\AiGuardrails\Http\FirewallController;
use Padosoft\AiGuardrails\Http\OutputStatsController;
use Padosoft\AiGuardrails\Http\OverviewController;
use Padosoft\AiGuardrails\Http\SettingsController;
use Padosoft\AiGuardrails\Http\TryController;

/**
 * Route-registration closure for the read/config HTTP API. Registered by the service provider only
 * when ai-guardrails.api.enabled is true. Names are grouped under `ai-guardrails.api.`.
 *
 * @param  list<string>  $middleware
 */
return static function (Registrar $router, string $prefix, array $middleware): void {
    $router->group([
        'prefix' => $prefix,
        'middleware' => $middleware,
        'as' => 'ai-guardrails.api.',
    ], static function () use ($router): void {
        $router->get('/overview', [OverviewController::class, 'index'])->name('overview');

        // Audit reads (append-only injection store). `trend` is registered before the `{id}` wildcard
        // so it is not captured as a detail id.
        $router->get('/audit', [AuditController::class, 'index'])->name('audit.index');
        $router->get('/audit/trend', [AuditController::class, 'trend'])->name('audit.trend');
        $router->get('/audit/{id}', [AuditController::class, 'show'])->name('audit.show');

        // Firewall rejections (Control A, append-only log).
        $router->get('/firewall', [FirewallController::class, 'index'])->name('firewall.index');

        // Output-handler stats (Control C, per-kind counters).
        $router->get('/output/stats', [OutputStatsController::class, 'index'])->name('output.stats');

        // HITL approvals (Control D): list pending + approve/reject by token.
        // NOTE: the token travels in the URL path and will appear in server/proxy/CDN access logs.
        // Operators MUST configure log scrubbing for the path `/approvals/{token}/(approve|reject)`
        // in any layer that retains access logs (nginx, caddy, API gateway, etc.).
        $router->get('/approvals', [ApprovalsController::class, 'index'])->name('approvals.index');
        $router->post('/approvals/{token}/approve', [ApprovalsController::class, 'approve'])
            ->where('token', '[A-Za-z0-9_\-\.]+')
            ->name('approvals.approve');
        $router->post('/approvals/{token}/reject', [ApprovalsController::class, 'reject'])
            ->where('token', '[A-Za-z0-9_\-\.]+')
            ->name('approvals.reject');

        // Runtime settings (effective overridable config; PUT persists allow-listed overrides).
        $router->get('/settings', [SettingsController::class, 'show'])->name('settings.show');
        $router->put('/settings', [SettingsController::class, 'update'])->name('settings.update');

        $router->post('/try/screen', [TryController::class, 'screen'])->name('try.screen');
        $router->post('/try/sanitize', [TryController::class, 'sanitize'])->name('try.sanitize');
    });
};
