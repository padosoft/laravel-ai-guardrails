<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Firewall\UserScopedArgumentScoper;
use Padosoft\AiGuardrails\Tests\TestCase;

final class UserScopedArgumentScoperTest extends TestCase
{
    public function test_overwrites_owner_keys_with_authenticated_principal(): void
    {
        $scoped = (new UserScopedArgumentScoper(['user_id', 'account_id']))->scope(
            ['order_id' => 'A1', 'user_id' => '999', 'account_id' => '999'],
            principalId: '42',
        );

        self::assertSame('42', $scoped['user_id']);
        self::assertSame('42', $scoped['account_id']);
        self::assertSame('A1', $scoped['order_id']);
    }

    public function test_injects_owner_key_even_when_model_omitted_it(): void
    {
        $scoped = (new UserScopedArgumentScoper(['user_id']))->scope(['order_id' => 'A1'], principalId: '42');

        self::assertSame('42', $scoped['user_id']);
    }

    public function test_throws_when_principal_is_null_and_owner_key_is_present(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/user_id/');

        (new UserScopedArgumentScoper(['user_id']))->scope(['user_id' => '999'], principalId: null);
    }

    public function test_returns_arguments_unchanged_when_principal_is_null_and_no_owner_keys_configured(): void
    {
        $scoped = (new UserScopedArgumentScoper([]))->scope(['order_id' => 'A1'], principalId: null);

        self::assertSame(['order_id' => 'A1'], $scoped);
    }

    public function test_returns_arguments_unchanged_when_principal_is_null_and_no_owner_keys_in_arguments(): void
    {
        // owner key configured but not present in the call → safe to pass through
        $scoped = (new UserScopedArgumentScoper(['user_id']))->scope(['order_id' => 'A1'], principalId: null);

        self::assertSame(['order_id' => 'A1'], $scoped);
    }

    public function test_schema_aware_scoping_skips_owner_keys_not_declared_by_the_tool(): void
    {
        // account_id is a configured owner key but NOT in the tool schema → must not be injected
        // (otherwise the validator would reject it as an unknown argument).
        $scoped = (new UserScopedArgumentScoper(['user_id', 'account_id']))->scope(
            ['order_id' => 'A1'],
            principalId: '42',
            schemaTypes: ['user_id' => 'string', 'order_id' => 'string'],
        );

        self::assertSame('42', $scoped['user_id']);
        self::assertArrayNotHasKey('account_id', $scoped);
    }

    public function test_schema_aware_scoping_coerces_principal_to_integer_owner_type(): void
    {
        $scoped = (new UserScopedArgumentScoper(['account_id']))->scope(
            [],
            principalId: '42',
            schemaTypes: ['account_id' => 'integer'],
        );

        self::assertSame(42, $scoped['account_id']); // int, not '42'
    }
}
