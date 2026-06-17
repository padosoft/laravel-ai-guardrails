<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Padosoft\AiGuardrails\Audit\ArrayInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\NullInjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\PassthroughArgumentScoper;
use Padosoft\AiGuardrails\Firewall\PermissiveToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\SchemaToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\UserScopedArgumentScoper;
use Padosoft\AiGuardrails\Output\NullPiiRedaction;
use Padosoft\AiGuardrails\Output\PassthroughSanitizer;
use Padosoft\AiGuardrails\Screening\GuardrailInputMiddleware;
use Padosoft\AiGuardrails\Screening\NullInjectionScreener;
use Padosoft\AiGuardrails\Screening\PatternInjectionScreener;

final class AiGuardrailsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-guardrails.php', 'ai-guardrails');

        // Default null-object bindings. Later tasks replace these with real implementations.
        $this->app->singleton(InjectionScreener::class, NullInjectionScreener::class);
        $this->app->singleton(OutputSanitizer::class, PassthroughSanitizer::class);
        $this->app->singleton(PiiRedaction::class, NullPiiRedaction::class);

        // Control A — Tool firewall collaborators (configured from the tool_firewall block).
        // The master kill-switch (ai-guardrails.enabled) degrades every control to pass-through.
        $firewallActive = (bool) config('ai-guardrails.enabled', true)
            && (bool) config('ai-guardrails.tool_firewall.enabled', true);
        if ($firewallActive) {
            $this->app->singleton(ArgumentScoper::class, static function ($app): ArgumentScoper {
                $ownerKeys = $app['config']->get('ai-guardrails.tool_firewall.owner_keys', []);

                return new UserScopedArgumentScoper(is_array($ownerKeys) ? array_values($ownerKeys) : []);
            });
            $this->app->singleton(ToolArgumentValidator::class, static function ($app): ToolArgumentValidator {
                $rejectUnknown = (bool) $app['config']->get('ai-guardrails.tool_firewall.reject_unknown_arguments', true);

                return new SchemaToolArgumentValidator($rejectUnknown);
            });
        } else {
            $this->app->singleton(ArgumentScoper::class, PassthroughArgumentScoper::class);
            $this->app->singleton(ToolArgumentValidator::class, PermissiveToolArgumentValidator::class);
        }

        // Control B — Input screening + append-only injection audit.
        $screenActive = (bool) config('ai-guardrails.enabled', true)
            && (bool) config('ai-guardrails.input_screen.enabled', true);
        if ($screenActive) {
            $this->app->singleton(InjectionScreener::class, static function ($app): InjectionScreener {
                $patterns = $app['config']->get('ai-guardrails.input_screen.patterns', []);
                $message = $app['config']->get('ai-guardrails.input_screen.refusal_message', 'This request was blocked by the input guardrails.');

                return new PatternInjectionScreener(
                    is_array($patterns) ? $patterns : [],
                    is_string($message) ? $message : 'This request was blocked by the input guardrails.',
                );
            });
        }

        $this->app->singleton(InjectionAuditStore::class, static function ($app): InjectionAuditStore {
            // Master kill-switch off → no persistence side effects.
            if (! (bool) $app['config']->get('ai-guardrails.enabled', true)) {
                return new NullInjectionAuditStore;
            }

            return match ($app['config']->get('ai-guardrails.audit.store', 'null')) {
                'array' => new ArrayInjectionAuditStore,
                'database' => new DatabaseInjectionAuditStore(
                    $app['config']->get('ai-guardrails.audit.connection'),
                    (string) $app['config']->get('ai-guardrails.audit.table', 'ai_guardrails_injection_audit'),
                ),
                default => new NullInjectionAuditStore,
            };
        });

        $this->app->singleton(GuardrailInputMiddleware::class, static function ($app): GuardrailInputMiddleware {
            $enabled = (bool) $app['config']->get('ai-guardrails.enabled', true)
                && (bool) $app['config']->get('ai-guardrails.input_screen.enabled', true);

            return new GuardrailInputMiddleware(
                $app->make(InjectionScreener::class),
                $app->make(InjectionAuditStore::class),
                // Resolve the principal defensively: auth may be unbound (CLI / minimal apps).
                static fn () => rescue(static fn () => auth()->guard()->id(), null, false),
                $enabled,
            );
        });

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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations/create_ai_guardrails_injection_audit_table.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_ai_guardrails_injection_audit_table.php'
                ),
            ], 'ai-guardrails-migrations');
        }

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

        // Refuse to boot with an open API surface. Fail CLOSED: any value that is not a
        // non-empty array of middleware (empty array, null, scalar — e.g. from a partial
        // package-config merge that does not restore nested defaults) is treated as "open".
        $apiMiddleware = config('ai-guardrails.api.middleware');
        if ((bool) config('ai-guardrails.api.enabled') && (! is_array($apiMiddleware) || $apiMiddleware === [])) {
            throw new \RuntimeException(
                'laravel-ai-guardrails: api.enabled is true but api.middleware is not a non-empty array. '.
                'Set at least one middleware (e.g. "auth:sanctum") in config/ai-guardrails.php to protect the API surface.'
            );
        }
    }
}
