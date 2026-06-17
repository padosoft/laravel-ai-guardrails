<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

use Closure;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\FirewalledTool;
use Padosoft\AiGuardrails\Hitl\ApprovalGatedTool;
use Padosoft\AiGuardrails\Output\StructuredOutputValidator;
use Padosoft\AiGuardrails\Screening\ScreenVerdict;

/**
 * The package's single PHP entry point (Facade-backed). Composes the four controls: screen() &
 * sanitize() are deterministic; guard() wraps a tool with the firewall (Control A); routeForApproval()
 * wraps a destructive tool with the HITL bridge (Control D); validateStructured() validates structured
 * model output (Control C). When the master kill-switch is off, the wrappers return the tool untouched.
 */
final readonly class AiGuardrails
{
    /**
     * @param  list<string>  $destructiveTools
     * @param  (Closure():(int|string|null))|null  $principalResolver
     */
    public function __construct(
        private InjectionScreener $screener,
        private OutputSanitizer $sanitizer,
        private PiiRedaction $pii,
        private ArgumentScoper $scoper,
        private ToolArgumentValidator $validator,
        private ApprovalRouter $router,
        private bool $enabled = true,
        private bool $hitlEnabled = false,
        private array $destructiveTools = [],
        private string $hitlFallback = 'deny',
        private string $destructiveMatch = 'exact',
        private ?Closure $principalResolver = null,
        private ?FirewallRejectionStore $firewallRejectionStore = null,
    ) {}

    public function screen(string $prompt): ScreenVerdict
    {
        return $this->screener->screen($prompt);
    }

    public function sanitize(string $text): string
    {
        return $this->pii->redact($this->sanitizer->sanitize($text));
    }

    /**
     * Wrap a tool with the firewall (re-scope owner keys + validate args). No-op when disabled.
     *
     * @param  (Closure():(int|string|null))|null  $principalResolver
     */
    public function guard(Tool $tool, ?Closure $principalResolver = null): Tool
    {
        if (! $this->enabled) {
            return $tool;
        }

        return new FirewalledTool($tool, $this->scoper, $this->validator, $this->resolver($principalResolver), $this->firewallRejectionStore);
    }

    /**
     * Wrap a destructive tool with the HITL approval bridge. No-op when disabled.
     *
     * @param  (Closure():(int|string|null))|null  $principalResolver
     */
    public function routeForApproval(Tool $tool, string $toolName, ?Closure $principalResolver = null): Tool
    {
        // No gating when the master kill-switch OR the HITL control is off (otherwise a 'deny'
        // fallback would wrongly block the tool even though approval gating is disabled).
        if (! $this->enabled || ! $this->hitlEnabled) {
            return $tool;
        }

        // Narrow to the literal union; any value other than 'pass' fails safe to 'deny'.
        $fallback = $this->hitlFallback === 'pass' ? 'pass' : 'deny';

        return new ApprovalGatedTool($tool, $this->router, $this->resolver($principalResolver), $toolName, $fallback);
    }

    /**
     * Whether a tool name is treated as destructive per config (exact or substring match).
     */
    public function isDestructive(string $toolName): bool
    {
        foreach ($this->destructiveTools as $destructive) {
            $matches = $this->destructiveMatch === 'substring'
                ? str_contains($toolName, $destructive)
                : $toolName === $destructive;

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $output
     * @param  array<string,Type>  $schema
     * @return array<string,string> field => violation message (empty = valid)
     */
    public function validateStructured(array $output, array $schema, bool $rejectUnknown = false): array
    {
        if (! $this->enabled) {
            return []; // master kill-switch off → no validation (pass-through)
        }

        return (new StructuredOutputValidator($rejectUnknown))->validate($output, $schema);
    }

    /**
     * @param  (Closure():(int|string|null))|null  $override
     * @return Closure():(int|string|null)
     */
    private function resolver(?Closure $override): Closure
    {
        return $override ?? $this->principalResolver ?? static fn (): null => null;
    }
}
