<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\Registrar;
use Padosoft\AiGuardrails\Http\AuditController;
use Padosoft\AiGuardrails\Http\FirewallController;
use Padosoft\AiGuardrails\Http\OutputStatsController;
use Padosoft\AiGuardrails\Http\OverviewController;
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

        $router->post('/try/screen', [TryController::class, 'screen'])->name('try.screen');
        $router->post('/try/sanitize', [TryController::class, 'sanitize'])->name('try.sanitize');
    });
};
