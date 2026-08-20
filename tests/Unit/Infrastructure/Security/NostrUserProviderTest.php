<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Infrastructure\Security;

use Innis\Nostr\SignIn\Application\Port\RoleAssignerInterface;
use Innis\Nostr\SignIn\Infrastructure\Security\NostrUser;
use Innis\Nostr\SignIn\Infrastructure\Security\NostrUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class NostrUserProviderTest extends TestCase
{
    private const string PUBKEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    public function testAPubkeyIdentifierBecomesAUserWithItsRoles(): void
    {
        $user = $this->provider(['ROLE_USER', 'ROLE_ADMIN'])->loadUserByIdentifier(self::PUBKEY_HEX);

        self::assertSame(self::PUBKEY_HEX, $user->getUserIdentifier());
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testAnIdentifierThatIsNotAPubkeyIsUnknown(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->provider(['ROLE_USER'])->loadUserByIdentifier('not-a-key');
    }

    public function testRefreshingReReadsTheRoles(): void
    {
        $assigner = $this->createStub(RoleAssignerInterface::class);
        $assigner->method('rolesFor')->willReturn(['ROLE_USER', 'ROLE_ADMIN'], ['ROLE_USER']);
        $provider = new NostrUserProvider($assigner);

        $admitted = $provider->loadUserByIdentifier(self::PUBKEY_HEX);
        $refreshed = $provider->refreshUser($admitted);

        self::assertSame(['ROLE_USER'], $refreshed->getRoles());
    }

    public function testAnotherProvidersUserIsNotRefreshedHere(): void
    {
        $this->expectException(UnsupportedUserException::class);

        $this->provider(['ROLE_USER'])->refreshUser(new InMemoryUser('someone', null));
    }

    public function testOnlyNostrUsersAreSupported(): void
    {
        self::assertTrue($this->provider(['ROLE_USER'])->supportsClass(NostrUser::class));
        self::assertFalse($this->provider(['ROLE_USER'])->supportsClass(InMemoryUser::class));
    }

    /**
     * @param list<string> $roles
     */
    private function provider(array $roles): NostrUserProvider
    {
        $assigner = $this->createStub(RoleAssignerInterface::class);
        $assigner->method('rolesFor')->willReturn($roles);

        return new NostrUserProvider($assigner);
    }
}
