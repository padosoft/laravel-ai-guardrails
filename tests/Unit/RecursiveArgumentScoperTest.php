<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use LogicException;
use Padosoft\AiGuardrails\Firewall\UserScopedArgumentScoper;
use PHPUnit\Framework\TestCase;

/** E7 — owner_key_depth=recursive re-scopes owner keys at any nesting depth. */
final class RecursiveArgumentScoperTest extends TestCase
{
    public function test_recursive_overwrites_owner_keys_at_every_depth(): void
    {
        $scoper = new UserScopedArgumentScoper(['user_id'], recursive: true);

        $result = $scoper->scope([
            'order' => [
                'user_id' => '999',
                'items' => [
                    ['user_id' => '888', 'sku' => 'A'],
                ],
            ],
        ], '42');

        self::assertSame('42', $result['order']['user_id']);
        self::assertSame('42', $result['order']['items'][0]['user_id']);
        self::assertSame('A', $result['order']['items'][0]['sku']); // non-owner keys untouched
    }

    public function test_top_level_mode_leaves_nested_owner_keys_alone(): void
    {
        $scoper = new UserScopedArgumentScoper(['user_id'], recursive: false);

        $result = $scoper->scope(['order' => ['user_id' => '999']], '42');

        // Only the top level is re-scoped; the nested model-supplied owner key is NOT (Task 2 behaviour).
        self::assertSame('999', $result['order']['user_id']);
    }

    public function test_top_level_injection_is_unchanged_in_recursive_mode(): void
    {
        $scoper = new UserScopedArgumentScoper(['user_id'], recursive: true);

        // An omitted top-level owner key is still injected; nested objects are not (no schema there).
        $result = $scoper->scope(['note' => 'hi'], '42');

        self::assertSame('42', $result['user_id']);
    }

    public function test_recursive_refuses_a_nested_owner_key_with_no_principal(): void
    {
        $scoper = new UserScopedArgumentScoper(['user_id'], recursive: true);

        $this->expectException(LogicException::class);
        $scoper->scope(['order' => ['user_id' => '999']], null);
    }

    public function test_top_level_mode_does_not_refuse_on_a_nested_owner_key_with_no_principal(): void
    {
        $scoper = new UserScopedArgumentScoper(['user_id'], recursive: false);

        // top_level only inspects the top level — a nested owner key is out of scope (no throw).
        $result = $scoper->scope(['order' => ['user_id' => '999']], null);
        self::assertSame('999', $result['order']['user_id']);
    }

    public function test_owner_key_whose_value_is_an_array_is_not_rewritten(): void
    {
        $scoper = new UserScopedArgumentScoper(['user_id'], recursive: true);

        // A structurally-odd owner key holding an array is not a principal id — leave it as-is.
        $result = $scoper->scope(['wrap' => ['user_id' => ['nested' => 1]]], '42');
        self::assertSame(['nested' => 1], $result['wrap']['user_id']);
    }
}
