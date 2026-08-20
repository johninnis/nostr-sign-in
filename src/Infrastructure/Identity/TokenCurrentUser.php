<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Infrastructure\Identity;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Application\Port\CurrentUserInterface;
use Override;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class TokenCurrentUser implements CurrentUserInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    // Deliberate: the pubkey is read off the token identifier, not a user class, so a host may resolve its own users — see ADR-0002
    #[Override]
    public function pubkey(): ?PublicKey
    {
        $identifier = $this->tokenStorage->getToken()?->getUserIdentifier();

        return null === $identifier ? null : PublicKey::tryFromHex($identifier);
    }
}
