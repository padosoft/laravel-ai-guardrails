<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Doubles;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class FakeOwnedTool implements Tool
{
    /** @var array<string,mixed>|null Captured args from the last handle() call. */
    public ?array $received = null;

    public function description(): Stringable|string
    {
        return 'Issue a refund for an order.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->received = $request->toArray();

        return 'ok';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->required(),
            'amount' => $schema->integer(),
            'user_id' => $schema->string(), // owner key the model must NOT control
        ];
    }
}
