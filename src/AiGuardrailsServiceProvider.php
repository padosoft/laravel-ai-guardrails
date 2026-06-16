<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

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
    }
}
