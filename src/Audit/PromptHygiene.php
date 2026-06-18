<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use Padosoft\AiGuardrails\Contracts\PiiRedaction;

/**
 * Audit data hygiene (Task E5). The append-only audit stores the raw prompt verbatim by default,
 * which can capture PII or secrets the user pasted. `audit_hygiene.prompt_storage` chooses how the
 * prompt is transformed BEFORE it is persisted:
 *
 *  - `raw`      — store verbatim (no transformation). The original posture.
 *  - `redact`   — compose laravel-pii-redactor to strip detected PII (DEFAULT). Graceful: when the
 *                 redactor package is absent the bound PiiRedaction is a null-object → behaves like raw.
 *  - `hash`     — store only `sha256:<hex>` so identical prompts still correlate but no content is kept.
 *  - `truncate` — keep only the first `truncate_at` Unicode code points (bounds accidental capture).
 *
 * Applied at the store boundary (HygienicInjectionAuditStore) so EVERY append path — middleware and
 * the artisan screen command — is covered. Domain events still carry the raw prompt (in-process).
 */
final readonly class PromptHygiene
{
    public function __construct(
        private string $mode,
        private int $truncateAt,
        private PiiRedaction $pii,
    ) {}

    public function apply(string $prompt): string
    {
        return match ($this->mode) {
            'raw' => $prompt,
            'hash' => 'sha256:'.hash('sha256', $prompt),
            'truncate' => mb_substr($prompt, 0, max(0, $this->truncateAt), 'UTF-8'),
            // 'redact' is the default; any unrecognised value fails safe to redaction (never raw).
            default => $this->pii->redact($prompt),
        };
    }

    /**
     * True when this mode MAY change the prompt content (reports mode capability, not runtime result).
     * Note: `redact` returns true even when the PII package is absent (null-object) and no substitution
     * actually occurs at runtime. The decorator uses a string-equality check on the actual output — not
     * this method — to decide whether the matched-span still aligns with the stored prompt.
     */
    public function transformsContent(): bool
    {
        return $this->mode !== 'raw';
    }
}
