<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\LaravelFlow\Facades\Flow;

/**
 * Chooses the approval router at boot, keeping the optional-vendor (laravel-flow) reference inside
 * the src/Hitl boundary. When HITL is disabled or flow is absent, degrades to NullApprovalRouter.
 */
final class ApprovalRouterFactory
{
    public static function make(bool $hitlEnabled): ApprovalRouter
    {
        if ($hitlEnabled && class_exists(Flow::class)) {
            return new FlowApprovalRouter;
        }

        return new NullApprovalRouter;
    }
}
