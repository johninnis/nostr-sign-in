<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Infrastructure\Security;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Application\Port\RoleAssignerInterface;
use Override;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<NostrUser>
 */
final readonly class NostrUserProvider implements UserProviderInterface
{
    public function __construct(
        private RoleAssignerInterface $roleAssigner,
    ) {
    }

    #[Override]
    public function loadUserByIdentifier(string $identifier): NostrUser
    {
        $pubkey = PublicKey::tryFromHex($identifier)
            ?? throw new UserNotFoundException('The identifier is not a public key.');

        return new NostrUser($pubkey, $this->roleAssigner->rolesFor($pubkey));
    }

    // Deliberate: roles are re-read on every request, and a changed set deauthenticates the session rather than downgrading it — see ADR-0002
    #[Override]
    public function refreshUser(UserInterface $user): NostrUser
    {
        if (!$user instanceof NostrUser) {
            throw new UnsupportedUserException(sprintf('Expected %s, got %s', NostrUser::class, $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    #[Override]
    public function supportsClass(string $class): bool
    {
        return NostrUser::class === $class;
    }
}
