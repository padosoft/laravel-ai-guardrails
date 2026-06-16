<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Output\NullPiiRedaction;
use Padosoft\AiGuardrails\Output\PassthroughSanitizer;
use Padosoft\AiGuardrails\Screening\NullInjectionScreener;

final class AiGuardrailsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-guardrails.php', 'ai-guardrails');

        // Default null-object bindings. Later tasks replace these with real implementations.
        $this->app->singleton(InjectionScreener::class, NullInjectionScreener::class);
        $this->app->singleton(OutputSanitizer::class, PassthroughSanitizer::class);
        $this->app->singleton(PiiRedaction::class, NullPiiRedaction::class);

        $this->app->singleton(AiGuardrails::class, static fn ($app): AiGuardrails => new AiGuardrails(
            $app->make(InjectionScreener::class),
            $app->make(OutputSanitizer::class),
            $app->make(PiiRedaction::class),
        ));
        $this->app->alias(AiGuardrails::class, 'ai-guardrails');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-guardrails.php' => config_path('ai-guardrails.php'),
        ], 'ai-guardrails-config');

        // Warn operators when guardrails are declared enabled but null-object scaffolding is still active.
        // Skip during unit tests to avoid noise; stops automatically once real implementations replace null objects.
        if (
            (bool) config('ai-guardrails.enabled') &&
            ! $this->app->runningUnitTests() &&
            $this->app->make(InjectionScreener::class) instanceof NullInjectionScreener
        ) {
            Log::warning(
                'laravel-ai-guardrails: package is enabled but running with null-object placeholder implementations. '.
                'Real controls (A–D) are not yet active. Do NOT use in production until the feature implementations are bound.'
            );
        }

        // Refuse to boot with an open API surface that has no middleware.
        if ((bool) config('ai-guardrails.api.enabled') && config('ai-guardrails.api.middleware') === []) {
            throw new \RuntimeException(
                'laravel-ai-guardrails: api.enabled is true but api.middleware is empty. '.
                'Set at least one middleware (e.g. "auth:sanctum") in config/ai-guardrails.php to protect the API surface.'
            );
        }
    }
}
