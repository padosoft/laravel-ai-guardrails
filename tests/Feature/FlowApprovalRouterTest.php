<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Hitl\FlowApprovalRouter;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\TestCase;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\IssuedApprovalToken;

final class FlowApprovalRouterTest extends TestCase
{
    /**
     * A plain spy swapped in for the Flow facade root. Avoids Mockery (the flow classes are final)
     * and flow's full persistence setup — it just records the adapter's calls and returns a real
     * FlowRun carrying an issued approval token.
     */
    private function flowSpy(): object
    {
        return new class
        {
            /** @var list<array{string,array<int,mixed>}> */
            public array $calls = [];

            public function define(string $name): object
            {
                return new class
                {
                    public function withInput(array $required): self
                    {
                        return $this;
                    }

                    public function approvalGate(string $name): self
                    {
                        return $this;
                    }

                    public function step(string $name, string $handler): self
                    {
                        return $this;
                    }

                    public function register(): void {}
                };
            }

            public function execute(string $name, array $input, mixed $options = null): FlowRun
            {
                $run = new FlowRun('run-1', $name, false, new DateTimeImmutable);
                $run->recordApprovalToken(new IssuedApprovalToken(
                    'appr-1', 'run-1', 'approval', 'plain-tok', 'hash', new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                ));

                return $run;
            }

            public function resume(string $token, array $payload = [], array $actor = []): void
            {
                $this->calls[] = ['resume', [$token, $payload, $actor]];
            }

            public function reject(string $token, array $payload = [], array $actor = []): void
            {
                $this->calls[] = ['reject', [$token, $payload, $actor]];
            }
        };
    }

    public function test_route_parks_the_call_and_maps_the_issued_token(): void
    {
        Flow::swap($this->flowSpy());

        $pending = (new FlowApprovalRouter)->route('refund', FakeDestructiveTool::class, ['order_id' => 'A1'], '42');

        self::assertSame('plain-tok', $pending->token);
        self::assertSame('run-1', $pending->runId);
        self::assertSame('refund', $pending->toolName);
        self::assertSame(['order_id' => 'A1'], $pending->scopedArguments);
    }

    public function test_approve_and_reject_delegate_to_flow(): void
    {
        $spy = $this->flowSpy();
        Flow::swap($spy);

        $router = new FlowApprovalRouter;
        $router->approve('tok', ['by' => 'op']);
        $router->reject('tok');

        self::assertSame(['resume', ['tok', [], ['by' => 'op']]], $spy->calls[0]);
        self::assertSame(['reject', ['tok', [], []]], $spy->calls[1]);
    }
}
