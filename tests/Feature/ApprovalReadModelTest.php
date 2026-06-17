<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\Hitl\ApprovalReadModel;
use Padosoft\AiGuardrails\Tests\TestCase;

final class ApprovalReadModelTest extends TestCase
{
    public function test_pending_degrades_to_empty_when_flow_tables_are_absent(): void
    {
        // padosoft/laravel-flow is installed (require-dev) so FlowApprovalRecord exists, but no flow
        // migrations have run here — querying flow_approvals/flow_runs would throw. The read model
        // must catch that and return [] rather than 500 the read-only endpoint.
        self::assertSame([], (new ApprovalReadModel)->pending());
    }
}
