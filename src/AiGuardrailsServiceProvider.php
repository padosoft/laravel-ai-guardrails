<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Padosoft\AiGuardrails\Audit\ArrayInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\NullInjectionAuditStore;
use Padosoft\AiGuardrails\Console\GuardrailsAuditCommand;
use Padosoft\AiGuardrails\Console\GuardrailsSanitizeCommand;
use Padosoft\AiGuardrails\Console\GuardrailsScreenCommand;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Contracts\PromptNormalizer;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Exceptions\InvalidScreeningPattern;
use Padosoft\AiGuardrails\Firewall\PassthroughArgumentScoper;
use Padosoft\AiGuardrails\Firewall\PermissiveToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\SchemaToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\UserScopedArgumentScoper;
use Padosoft\AiGuardrails\Hitl\ApprovalRouterFactory;
use Padosoft\AiGuardrails\Output\GuardrailOutputMiddleware;
use Padosoft\AiGuardrails\Output\HtmlMarkdownSanitizer;
use Padosoft\AiGuardrails\Output\PassthroughSanitizer;
use Padosoft\AiGuardrails\Output\PiiRedactionFactory;
use Padosoft\AiGuardrails\Screening\GuardrailInputMiddleware;
use Padosoft\AiGuardrails\Screening\NullInjectionScreener;
use Padosoft\AiGuardrails\Screening\NullPromptNormalizer;
use Padosoft\AiGuardrails\Screening\PatternInjectionScreener;
use Padosoft\AiGuardrails\Screening\UnicodePromptNormalizer;

final class AiGuardrailsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-guardrails.php', 'ai-guardrails');

        // Default null-object bindings. Later tasks replace these with real implementations.
        $this->app->singleton(InjectionScreener::class, NullInjectionScreener::class);

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
        $this->app->singleton(PromptNormalizer::class, static function ($app): PromptNormalizer {
            $cfg = $app['config'];
            if (! (bool) $cfg->get('ai-guardrails.normalization.enabled', true)) {
                return new NullPromptNormalizer;
            }

            return new UnicodePromptNormalizer(
                (bool) $cfg->get('ai-guardrails.normalization.nfkc', true),
                (bool) $cfg->get('ai-guardrails.normalization.strip_zero_width', true),
                (bool) $cfg->get('ai-guardrails.normalization.strip_control', true),
                (bool) $cfg->get('ai-guardrails.normalization.casefold', true),
            );
        });

        $screenActive = (bool) config('ai-guardrails.enabled', true)
            && (bool) config('ai-guardrails.input_screen.enabled', true);
        if ($screenActive) {
            $this->app->singleton(InjectionScreener::class, static function ($app): InjectionScreener {
                $patterns = $app['config']->get('ai-guardrails.input_screen.patterns', []);
                $message = $app['config']->get('ai-guardrails.input_screen.refusal_message', 'This request was blocked by the input guardrails.');

                if (! is_array($patterns)) {
                    // A mis-typed patterns config would silently disable screening (fail open) — make it loud.
                    Log::warning(
                        'laravel-ai-guardrails: input_screen.patterns is not an array; no screening patterns are active.'
                    );
                    $patterns = [];
                }

                $maxLength = (int) $app['config']->get('ai-guardrails.normalization.max_prompt_length', 0);

                return new PatternInjectionScreener(
                    $patterns,
                    is_string($message) ? $message : 'This request was blocked by the input guardrails.',
                    $app->make(PromptNormalizer::class),
                    max(0, $maxLength),
                    (string) $app['config']->get('ai-guardrails.pattern_safety.ruleset_version', 'v1'),
                    (string) $app['config']->get('ai-guardrails.pattern_safety.on_match_error', 'closed'),
                    max(0, (int) $app['config']->get('ai-guardrails.pattern_safety.pcre_backtrack_limit', 0)),
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
                    is_string($conn = $app['config']->get('ai-guardrails.audit.connection')) ? $conn : null,
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

        // Control C — Output handler (sanitize untrusted model output + compose PII redaction).
        // Config is read lazily inside the closures (not at registration time) so that the
        // config repository is guaranteed to be fully bootstrapped when the singleton resolves.
        $this->app->singleton(OutputSanitizer::class, static function ($app): OutputSanitizer {
            if (
                ! (bool) $app['config']->get('ai-guardrails.enabled', true)
                || ! (bool) $app['config']->get('ai-guardrails.output_handler.enabled', true)
            ) {
                return new PassthroughSanitizer;
            }

            return new HtmlMarkdownSanitizer(
                (bool) $app['config']->get('ai-guardrails.output_handler.sanitize_html', true),
                (bool) $app['config']->get('ai-guardrails.output_handler.neutralize_markdown', true),
                (string) $app['config']->get('ai-guardrails.output_handler.html_mode', 'escape'),
            );
        });

        $this->app->singleton(PiiRedaction::class, static function ($app): PiiRedaction {
            $active = (bool) $app['config']->get('ai-guardrails.enabled', true)
                && (bool) $app['config']->get('ai-guardrails.output_handler.enabled', true);

            return PiiRedactionFactory::make(
                $app,
                $active && (bool) $app['config']->get('ai-guardrails.output_handler.redact_pii', true),
            );
        });

        $this->app->singleton(GuardrailOutputMiddleware::class, static function ($app): GuardrailOutputMiddleware {
            $enabled = (bool) $app['config']->get('ai-guardrails.enabled', true)
                && (bool) $app['config']->get('ai-guardrails.output_handler.enabled', true);

            return new GuardrailOutputMiddleware(
                $app->make(OutputSanitizer::class),
                $app->make(PiiRedaction::class),
                $enabled,
            );
        });

        // Control D — HITL approval bridge (graceful: FlowApprovalRouter when flow present + enabled).
        $this->app->singleton(ApprovalRouter::class, static fn ($app): ApprovalRouter => ApprovalRouterFactory::make(
            (bool) $app['config']->get('ai-guardrails.enabled', true)
                && (bool) $app['config']->get('ai-guardrails.hitl.enabled', false),
        ));

        $this->app->singleton(AiGuardrails::class, static function ($app): AiGuardrails {
            $cfg = $app['config'];
            $destructive = $cfg->get('ai-guardrails.hitl.destructive_tools', []);

            return new AiGuardrails(
                $app->make(InjectionScreener::class),
                $app->make(OutputSanitizer::class),
                $app->make(PiiRedaction::class),
                $app->make(ArgumentScoper::class),
                $app->make(ToolArgumentValidator::class),
                $app->make(ApprovalRouter::class),
                (bool) $cfg->get('ai-guardrails.enabled', true),
                (bool) $cfg->get('ai-guardrails.hitl.enabled', false),
                is_array($destructive) ? array_values(array_filter($destructive, 'is_string')) : [],
                (string) $cfg->get('ai-guardrails.hitl.fallback', 'deny'),
                (string) $cfg->get('ai-guardrails.tool_authorization.destructive_match', 'exact'),
                static fn () => rescue(static fn () => auth()->guard()->id(), null, false),
            );
        });
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

            $this->commands([
                GuardrailsScreenCommand::class,
                GuardrailsSanitizeCommand::class,
                GuardrailsAuditCommand::class,
            ]);
        }

        // E2: validate screening patterns up front so a malformed regex fails loudly at boot
        // instead of erroring on the first prompt screened.
        if (
            (bool) config('ai-guardrails.enabled', true)
            && (bool) config('ai-guardrails.input_screen.enabled', true)
            && (bool) config('ai-guardrails.pattern_safety.validate_at_boot', true)
        ) {
            $patterns = config('ai-guardrails.input_screen.patterns', []);
            if (is_array($patterns)) {
                $patternErrors = PatternInjectionScreener::validatePatterns($patterns);
                if ($patternErrors !== []) {
                    throw new InvalidScreeningPattern($patternErrors);
                }
            }
        }

        // Refuse to boot with an open API surface. Fail CLOSED on the EFFECTIVE (string-filtered)
        // middleware — a non-empty array of non-strings would otherwise pass a raw check and then
        // register the routes with no usable middleware.
        $raw = config('ai-guardrails.api.middleware');
        $effectiveMiddleware = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];

        if ((bool) config('ai-guardrails.api.enabled') && $effectiveMiddleware === []) {
            throw new \RuntimeException(
                'laravel-ai-guardrails: api.enabled is true but api.middleware has no (string) middleware. '.
                'Set at least one middleware (e.g. "auth:sanctum") in config/ai-guardrails.php to protect the API surface.'
            );
        }

        $this->registerApiRoutes($effectiveMiddleware);
    }

    /**
     * Register the read/config HTTP API routes — only when api.enabled (default OFF), the router is
     * bound, and routes are not cached. The caller passes the guarded, non-empty middleware.
     *
     * @param  list<string>  $middleware
     */
    private function registerApiRoutes(array $middleware): void
    {
        if (! (bool) config('ai-guardrails.api.enabled', false)) {
            return;
        }

        if ($middleware === [] || ! $this->app->bound(Registrar::class)) {
            return;
        }

        if (method_exists($this->app, 'routesAreCached') && $this->app->routesAreCached()) {
            return;
        }

        $prefix = trim((string) config('ai-guardrails.api.prefix', 'ai-guardrails/api'), '/');

        /** @var callable(Registrar, string, list<string>): void $register */
        $register = require __DIR__.'/../routes/ai-guardrails-api.php';
        $register(
            $this->app->make(Registrar::class),
            $prefix !== '' ? $prefix : 'ai-guardrails/api',
            $middleware,
        );
    }
}
