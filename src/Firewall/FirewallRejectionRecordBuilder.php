<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Padosoft\AiGuardrails\Support\AppendOnlyEloquentBuilder;

/**
 * Append-only Eloquent builder for the firewall rejection record. All mutators throw — see
 * {@see AppendOnlyEloquentBuilder} for the rationale and the limits of this guarantee.
 *
 * @extends AppendOnlyEloquentBuilder<FirewallRejectionRecord>
 */
final class FirewallRejectionRecordBuilder extends AppendOnlyEloquentBuilder
{
    protected function storeLabel(): string
    {
        return 'The firewall rejection log';
    }
}
