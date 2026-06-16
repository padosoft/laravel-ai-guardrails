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

    public function test_leaves_arguments_untouched_when_principal_is_null(): void
    {
        $scoped = (new UserScopedArgumentScoper(['user_id']))->scope(['user_id' => '999'], principalId: null);

        self::assertSame('999', $scoped['user_id']);
    }
}
