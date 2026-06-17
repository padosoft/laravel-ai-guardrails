<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Console;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Padosoft\AiGuardrails\AiGuardrails;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

final class GuardrailsScreenCommand extends Command
{
    protected $signature = 'ai-guardrails:screen {prompt? : The prompt to screen (reads STDIN if omitted)}';

    protected $description = 'Screen a prompt for injection patterns, print the verdict, and audit the attempt.';

    public function handle(AiGuardrails $guardrails, InjectionAuditStore $audit): int
    {
        $prompt = $this->resolvePrompt();
        if ($prompt === '') {
            $this->error('No prompt provided (pass an argument or pipe via STDIN).');

            return self::INVALID;
        }

        $verdict = $guardrails->screen($prompt);

        $audit->append(new InjectionAttempt(
            $prompt,
            $verdict->blocked,
            $verdict->ruleId,
            null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $verdict->rulesetVersion,
            $verdict->erroredRuleIds,
        ));

        if ($verdict->blocked) {
            $this->error("BLOCKED (rule: {$verdict->ruleId})");
            $this->line($verdict->refusalMessage ?? '');

            return self::FAILURE;
        }

        $this->info('ALLOWED');

        return self::SUCCESS;
    }

    private function resolvePrompt(): string
    {
        $arg = $this->argument('prompt');
        if (is_string($arg) && $arg !== '') {
            return $arg;
        }

        return trim((string) file_get_contents('php://stdin'));
    }
}
