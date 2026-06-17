<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Doubles;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class FakeDestructiveTool implements Tool
{
    public bool $executed = false;

    /** @var array<string,mixed>|null */
    public ?array $received = null;

    public function description(): Stringable|string
    {
        return 'Refund an order.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->executed = true;
        $this->received = $request->toArray();

        return 'refunded';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['order_id' => $schema->string()->required()];
    }
}
