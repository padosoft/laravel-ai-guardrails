<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

use Illuminate\Support\ServiceProvider;

final class AiGuardrailsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-guardrails.php', 'ai-guardrails');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-guardrails.php' => config_path('ai-guardrails.php'),
        ], 'ai-guardrails-config');
    }
}
