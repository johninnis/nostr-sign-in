<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Infrastructure\Identity;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Infrastructure\Identity\TokenCurrentUser;
use Innis\Nostr\SignIn\Infrastructure\Security\NostrUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class TokenCurrentUserTest extends TestCase
{
    private const string PUBKEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    public function testTheTokensUserIsWhoIsActing(): void
    {
        $pubkey = PublicKey::tryFromHex(self::PUBKEY_HEX) ?? self::fail('fixture pubkey is not valid hex');
        $storage = new TokenStorage();
        $storage->setToken(new PostAuthenticationToken(new NostrUser($pubkey, ['ROLE_USER']), 'main', ['ROLE_USER']));

        self::assertSame(self::PUBKEY_HEX, new TokenCurrentUser($storage)->pubkey()?->toHex());
    }

    public function testNoTokenIsNobody(): void
    {
        self::assertNull(new TokenCurrentUser(new TokenStorage())->pubkey());
    }

    public function testAHostsOwnUserClassIsReadByItsIdentifier(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new PostAuthenticationToken(new InMemoryUser(self::PUBKEY_HEX, null), 'main', ['ROLE_USER']));

        self::assertSame(self::PUBKEY_HEX, new TokenCurrentUser($storage)->pubkey()?->toHex());
    }

    public function testAUserIdentifiedByAnythingElseIsNobodyToTheNostrSide(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new PostAuthenticationToken(new InMemoryUser('someone@example.test', null), 'main', ['ROLE_USER']));

        self::assertNull(new TokenCurrentUser($storage)->pubkey());
    }
}
