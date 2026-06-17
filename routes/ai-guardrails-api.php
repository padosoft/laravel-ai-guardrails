<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\Registrar;
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

        $router->post('/try/screen', [TryController::class, 'screen'])->name('try.screen');
        $router->post('/try/sanitize', [TryController::class, 'sanitize'])->name('try.sanitize');
    });
};
