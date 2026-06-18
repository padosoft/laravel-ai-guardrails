<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Padosoft\AiGuardrails\Audit\ArrayInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\HygienicInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\NullInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\PromptHygiene;
use Padosoft\AiGuardrails\Console\GuardrailsAuditCommand;
use Padosoft\AiGuardrails\Console\GuardrailsPurgeCommand;
use Padosoft\AiGuardrails\Console\GuardrailsSanitizeCommand;
use Padosoft\AiGuardrails\Console\GuardrailsScreenCommand;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;
use Padosoft\AiGuardrails\Contracts\GuardrailSettingsStore;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Contracts\PromptNormalizer;
use Padosoft\AiGuardrails\Contracts\SettingsChangeStore;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Contracts\ToolAuthorizer;
use Padosoft\AiGuardrails\Exceptions\InvalidScreeningPattern;
use Padosoft\AiGuardrails\Firewall\AllowAllToolAuthorizer;
use Padosoft\AiGuardrails\Firewall\ArrayFirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\DatabaseFirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\GateToolAuthorizer;
use Padosoft\AiGuardrails\Firewall\NullFirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\PassthroughArgumentScoper;
use Padosoft\AiGuardrails\Firewall\PermissiveToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\SchemaToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\UserScopedArgumentScoper;
use Padosoft\AiGuardrails\Hitl\ApprovalRouterFactory;
use Padosoft\AiGuardrails\Hitl\HitlInstallCommand;
use Padosoft\AiGuardrails\Hitl\HitlStatusCommand;
use Padosoft\AiGuardrails\Mcp\McpServerRegistrar;
use Padosoft\AiGuardrails\Output\ArrayOutputStatStore;
use Padosoft\AiGuardrails\Output\DatabaseOutputStatStore;
use Padosoft\AiGuardrails\Output\GuardrailOutputMiddleware;
use Padosoft\AiGuardrails\Output\HtmlSanitizerFactory;
use Padosoft\AiGuardrails\Output\NullOutputStatStore;
use Padosoft\AiGuardrails\Output\PassthroughSanitizer;
use Padosoft\AiGuardrails\Output\PiiRedactionFactory;
use Padosoft\AiGuardrails\Screening\GuardrailInputMiddleware;
use Padosoft\AiGuardrails\Screening\NullInjectionScreener;
use Padosoft\AiGuardrails\Screening\NullPromptNormalizer;
use Padosoft\AiGuardrails\Screening\PatternInjectionScreener;
use Padosoft\AiGuardrails\Screening\UnicodePromptNormalizer;
use Padosoft\AiGuardrails\Settings\ArraySettingsChangeStore;
use Padosoft\AiGuardrails\Settings\ConfigGuardrailSettingsStore;
use Padosoft\AiGuardrails\Settings\DatabaseGuardrailSettingsStore;
use Padosoft\AiGuardrails\Settings\DatabaseSettingsChangeStore;
use Padosoft\AiGuardrails\Settings\NullSettingsChangeStore;
use Padosoft\AiGuardrails\Settings\OverridableSettings;
use Padosoft\AiGuardrails\Support\ResolvesControlMode;

final class AiGuardrailsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-guardrails.php', 'ai-guardrails');

        // Overlay persisted runtime settings (DB store) onto the config BEFORE the controls are wired,
        // so what PUT /settings saves is actually what the screener/firewall/output/HITL enforce — not
        // just what the settings API reports. Without this the API would give a false view of what is
        // live. Effective on the next boot after a save (config changes apply per-boot, as usual).
        $this->overlayRuntimeSettings();

        // Default null-object bindings. Later tasks replace these with real implementations.
        $this->app->singleton(InjectionScreener::class, NullInjectionScreener::class);

        // Control A — Tool firewall collaborators (configured from the tool_firewall block).
        // A control is wired with REAL collaborators when its mode is active (enforce OR monitor); off
        // (incl. master kill-switch off) degrades it to the null-object pass-through implementations.
        $firewallActive = ResolvesControlMode::for('tool_firewall', 'ai-guardrails.tool_firewall.enabled')->isActive();
        if ($firewallActive) {
            $this->app->singleton(ArgumentScoper::class, static function ($app): ArgumentScoper {
                $ownerKeys = $app['config']->get('ai-guardrails.tool_firewall.owner_keys', []);
                // E7: owner_key_depth=recursive re-scopes owner keys at any nesting depth, not just top-level.
                // Fallback matches the published config default (recursive) and the fail-secure posture
                // (a missing key resolves to MORE scoping, never less). recursive only ever closes an IDOR
                // hole — it can't weaken a security-conscious tool. Only an explicit 'top_level' opts out.
                $recursive = $app['config']->get('ai-guardrails.tool_authorization.owner_key_depth', 'recursive') !== 'top_level';

                return new UserScopedArgumentScoper(is_array($ownerKeys) ? array_values($ownerKeys) : [], $recursive);
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
                (bool) $cfg->get('ai-guardrails.normalization.fold_confusables', true),
            );
        });

        $screenActive = ResolvesControlMode::for('input_screen', 'ai-guardrails.input_screen.enabled')->isActive();
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

            $store = match ($app['config']->get('ai-guardrails.audit.store', 'null')) {
                'array' => new ArrayInjectionAuditStore,
                'database' => new DatabaseInjectionAuditStore(
                    self::storeConnection('ai-guardrails.audit.connection'),
                    self::storeTable('ai-guardrails.audit.table', 'ai_guardrails_injection_audit'),
                ),
                default => new NullInjectionAuditStore,
            };

            // A null store never persists, so hygiene is a no-op there — only wrap a real store.
            if ($store instanceof NullInjectionAuditStore) {
                return $store;
            }

            // Audit data hygiene (E5): transform the prompt before it is persisted. The PII redactor is
            // resolved INDEPENDENTLY of the output handler (audit_hygiene must work even with Control C
            // off); PiiRedactionFactory keeps the optional-vendor reference inside src/Output.
            $hygiene = new PromptHygiene(
                (string) $app['config']->get('ai-guardrails.audit_hygiene.prompt_storage', 'redact'),
                (int) $app['config']->get('ai-guardrails.audit_hygiene.truncate_at', 2000),
                PiiRedactionFactory::make($app, true),
            );

            return new HygienicInjectionAuditStore($store, $hygiene);
        });

        $this->app->singleton(FirewallRejectionStore::class, static function ($app): FirewallRejectionStore {
            // Master kill-switch off → no persistence side effects.
            if (! (bool) $app['config']->get('ai-guardrails.enabled', true)) {
                return new NullFirewallRejectionStore;
            }

            return match ($app['config']->get('ai-guardrails.firewall_log.store', 'null')) {
                'array' => new ArrayFirewallRejectionStore,
                'database' => new DatabaseFirewallRejectionStore(
                    self::storeConnection('ai-guardrails.firewall_log.connection'),
                    self::storeTable('ai-guardrails.firewall_log.table', 'ai_guardrails_firewall_rejections'),
                ),
                default => new NullFirewallRejectionStore,
            };
        });

        $this->app->singleton(OutputStatStore::class, static function ($app): OutputStatStore {
            // Master kill-switch off → no persistence side effects.
            if (! (bool) $app['config']->get('ai-guardrails.enabled', true)) {
                return new NullOutputStatStore;
            }

            return match ($app['config']->get('ai-guardrails.output_stats.store', 'null')) {
                'array' => new ArrayOutputStatStore,
                'database' => new DatabaseOutputStatStore(
                    self::storeConnection('ai-guardrails.output_stats.connection'),
                    self::storeTable('ai-guardrails.output_stats.table', 'ai_guardrails_output_stats'),
                ),
                default => new NullOutputStatStore,
            };
        });

        $this->app->singleton(GuardrailSettingsStore::class, static function (): GuardrailSettingsStore {
            // Not master-switch-gated: an admin must be able to view/edit settings even when the
            // guardrails are off (e.g. to re-enable them).
            return match (config('ai-guardrails.settings.store', 'config')) {
                'database' => new DatabaseGuardrailSettingsStore(
                    self::storeConnection('ai-guardrails.settings.connection'),
                    self::storeTable('ai-guardrails.settings.table', 'ai_guardrails_settings'),
                ),
                default => new ConfigGuardrailSettingsStore,
            };
        });

        // E6 — append-only audit of settings changes (WHO changed WHAT via PUT /settings). Default-OFF
        // (null) like the other persistence stores; the admin enables array/database.
        $this->app->singleton(SettingsChangeStore::class, static function ($app): SettingsChangeStore {
            return match ($app['config']->get('ai-guardrails.settings_audit.store', 'null')) {
                'array' => new ArraySettingsChangeStore,
                'database' => new DatabaseSettingsChangeStore(
                    self::storeConnection('ai-guardrails.settings_audit.connection'),
                    self::storeTable('ai-guardrails.settings_audit.table', 'ai_guardrails_settings_changes'),
                ),
                default => new NullSettingsChangeStore,
            };
        });

        $this->app->singleton(GuardrailInputMiddleware::class, static function ($app): GuardrailInputMiddleware {
            $mode = ResolvesControlMode::for('input_screen', 'ai-guardrails.input_screen.enabled');

            return new GuardrailInputMiddleware(
                $app->make(InjectionScreener::class),
                $app->make(InjectionAuditStore::class),
                // Resolve the principal defensively: auth may be unbound (CLI / minimal apps).
                static fn () => rescue(static fn () => auth()->guard()->id(), null, false),
                $mode->isActive(),
                $mode,
                self::eventDispatcher($app),
            );
        });

        // Control C — Output handler (sanitize untrusted model output + compose PII redaction).
        // Config is read lazily inside the closures (not at registration time) so that the
        // config repository is guaranteed to be fully bootstrapped when the singleton resolves.
        $this->app->singleton(OutputSanitizer::class, static function ($app): OutputSanitizer {
            // Active when the output handler's mode is enforce OR monitor (monitor still needs the real
            // sanitizer to compute what enforcement WOULD neutralise for the stats).
            if (! ResolvesControlMode::for('output_handler', 'ai-guardrails.output_handler.enabled')->isActive()) {
                return new PassthroughSanitizer;
            }

            // L2: HtmlSanitizerFactory upgrades html_mode=allowlist to HTMLPurifier when installed,
            // gracefully falling back to the built-in allowlist otherwise (vendor ref stays in src/Output).
            return HtmlSanitizerFactory::make(
                (string) $app['config']->get('ai-guardrails.output_handler.html_mode', 'escape'),
                (bool) $app['config']->get('ai-guardrails.output_handler.sanitize_html', true),
                (bool) $app['config']->get('ai-guardrails.output_handler.neutralize_markdown', true),
            );
        });

        $this->app->singleton(PiiRedaction::class, static function ($app): PiiRedaction {
            $active = ResolvesControlMode::for('output_handler', 'ai-guardrails.output_handler.enabled')->isActive();

            return PiiRedactionFactory::make(
                $app,
                $active && (bool) $app['config']->get('ai-guardrails.output_handler.redact_pii', true),
            );
        });

        $this->app->singleton(GuardrailOutputMiddleware::class, static function ($app): GuardrailOutputMiddleware {
            $mode = ResolvesControlMode::for('output_handler', 'ai-guardrails.output_handler.enabled');

            return new GuardrailOutputMiddleware(
                $app->make(OutputSanitizer::class),
                $app->make(PiiRedaction::class),
                $mode->isActive(),
                $app->make(OutputStatStore::class),
                $mode,
                self::eventDispatcher($app),
                (bool) $app['config']->get('ai-guardrails.output_handler.sanitize_tool_calls', false),
            );
        });

        // Control D — HITL approval bridge (graceful: FlowApprovalRouter when flow present + enforcing).
        // Monitor mode never calls the router (the gated tool runs the delegate directly), so only an
        // ENFORCING hitl mode needs the real router; otherwise the null-object router is bound.
        $this->app->singleton(ApprovalRouter::class, static fn (): ApprovalRouter => ApprovalRouterFactory::make(
            ResolvesControlMode::for('hitl', 'ai-guardrails.hitl.enabled')->enforces(),
        ));

        // E7 — tool authorization gate. Bound to the Gate-backed authorizer when enabled, else the
        // allow-all null-object. AiGuardrails only wraps with AuthorizedTool when authz is ENABLED.
        $this->app->singleton(ToolAuthorizer::class, static function ($app): ToolAuthorizer {
            if (! (bool) $app['config']->get('ai-guardrails.tool_authorization.enabled', false)) {
                return new AllowAllToolAuthorizer;
            }

            return new GateToolAuthorizer(
                $app->make(Gate::class),
                (string) $app['config']->get('ai-guardrails.tool_authorization.ability', 'ai-guardrails:use-tool'),
            );
        });

        $this->app->singleton(AiGuardrails::class, static function ($app): AiGuardrails {
            $cfg = $app['config'];
            $destructive = $cfg->get('ai-guardrails.hitl.destructive_tools', []);
            $authzEnabled = (bool) $cfg->get('ai-guardrails.tool_authorization.enabled', false);

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
                $app->make(FirewallRejectionStore::class),
                $app->make(OutputStatStore::class),
                ResolvesControlMode::for('tool_firewall', 'ai-guardrails.tool_firewall.enabled'),
                ResolvesControlMode::for('hitl', 'ai-guardrails.hitl.enabled'),
                self::eventDispatcher($app),
                // Only wire the authorizer when enabled, so guard() wraps with AuthorizedTool only then.
                $authzEnabled ? $app->make(ToolAuthorizer::class) : null,
            );
        });
        $this->app->alias(AiGuardrails::class, 'ai-guardrails');
    }

    /**
     * The event dispatcher to wire into the controls — null when domain events are disabled
     * (`events.enabled=false`), so each control's `?Dispatcher` falls back to emitting nothing. E4.
     *
     * NOTE: evaluated once at first container resolution (singleton build time). A runtime-settings
     * overlay (E6) that flips `events.enabled` after first resolution will NOT take effect until
     * the singleton is re-built (e.g., `app()->forgetInstance(...)` or a new request lifecycle).
     */
    private static function eventDispatcher(Application $app): ?Dispatcher
    {
        if (! (bool) config('ai-guardrails.events.enabled', true)) {
            return null;
        }

        return $app->make(Dispatcher::class);
    }

    /**
     * Overlay persisted runtime settings (database store) onto `config('ai-guardrails.*')` so the
     * controls actually enforce what the admin saved. No-op for config-store mode. Fails safe (keeps
     * file config) when the table is absent or a row is corrupt — a malformed/unreadable row must
     * never silently disable a security control.
     */
    private function overlayRuntimeSettings(): void
    {
        if (config('ai-guardrails.settings.store') !== 'database') {
            return;
        }

        // Master kill-switch off → every control is passthrough anyway, so overlaying their settings
        // is pointless; skip the per-boot query. (The master switch is not itself overridable.)
        if (! config('ai-guardrails.enabled', true)) {
            return;
        }

        if (OverridableSettings::keys() === []) {
            return; // nothing to overlay → no DB round-trip.
        }

        // Delegate to the store so the DB read + JSON decoding + fail-safe (skip corrupt/null/
        // type-mismatched) policy lives in ONE place — `all()` returns the effective overridable map
        // (defaults overlaid with valid overrides), and fails safe to the file defaults if the table
        // is missing. Overlaying a default back onto itself is a harmless no-op.
        $store = new DatabaseGuardrailSettingsStore(
            self::storeConnection('ai-guardrails.settings.connection'),
            self::storeTable('ai-guardrails.settings.table', 'ai_guardrails_settings'),
        );

        foreach ($store->all() as $key => $value) {
            config(['ai-guardrails.'.$key => $value]);
        }
    }

    /**
     * Resolve a configured store connection, treating a missing OR empty-string value as null (use
     * the app's default connection). Empty env vars are common and must degrade safely.
     */
    private static function storeConnection(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Resolve a configured store table, falling back to the default when missing or empty. */
    private static function storeTable(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $default;
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
                __DIR__.'/../database/migrations/create_ai_guardrails_firewall_rejections_table.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His', time() + 1).'_create_ai_guardrails_firewall_rejections_table.php'
                ),
                __DIR__.'/../database/migrations/create_ai_guardrails_output_stats_table.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His', time() + 2).'_create_ai_guardrails_output_stats_table.php'
                ),
                __DIR__.'/../database/migrations/create_ai_guardrails_settings_table.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His', time() + 3).'_create_ai_guardrails_settings_table.php'
                ),
                __DIR__.'/../database/migrations/create_ai_guardrails_settings_changes_table.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His', time() + 4).'_create_ai_guardrails_settings_changes_table.php'
                ),
            ], 'ai-guardrails-migrations');

            $this->commands([
                GuardrailsScreenCommand::class,
                GuardrailsSanitizeCommand::class,
                GuardrailsAuditCommand::class,
                GuardrailsPurgeCommand::class,
                HitlStatusCommand::class,
                HitlInstallCommand::class,
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

        // MCP surface (Task L5) — DEFAULT-OFF. The registrar keeps the laravel/mcp reference inside
        // src/Mcp and no-ops when the package is absent (compose-not-couple).
        if ((bool) config('ai-guardrails.mcp.enabled', false)) {
            McpServerRegistrar::registerIfAvailable();
        }
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
