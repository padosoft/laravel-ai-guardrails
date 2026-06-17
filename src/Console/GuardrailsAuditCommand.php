<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Console;

use Illuminate\Console\Command;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

final class GuardrailsAuditCommand extends Command
{
    protected $signature = 'ai-guardrails:audit {--limit=20 : How many recent attempts to show}';

    protected $description = 'List recent injection-audit attempts (blocked and allowed).';

    public function handle(InjectionAuditStore $audit): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = $audit->recent($limit);

        if ($rows === []) {
            $this->info('No injection attempts recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['When (UTC)', 'Verdict', 'Rule', 'Ruleset', 'Principal', 'Prompt'],
            array_map(static fn (InjectionAttempt $a): array => [
                $a->occurredAt->format('Y-m-d H:i:s'),
                $a->blocked ? 'BLOCKED' : 'allowed',
                $a->ruleId ?? '—',
                $a->rulesetVersion ?? '—',
                $a->principalId ?? '—',
                mb_strimwidth($a->prompt, 0, 60, '…'),
            ], $rows),
        );

        return self::SUCCESS;
    }
}
