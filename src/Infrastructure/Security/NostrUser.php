<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Infrastructure\Security;

use Deprecated;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use InvalidArgumentException;
use Override;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class NostrUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private PublicKey $pubkey,
        private array $roles,
    ) {
    }

    public function getPubkey(): PublicKey
    {
        return $this->pubkey;
    }

    #[Override]
    public function getRoles(): array
    {
        return $this->roles;
    }

    #[Override]
    public function getUserIdentifier(): string
    {
        return $this->pubkey->toHex();
    }

    #[Deprecated('there are no credentials to erase; the interface removes the method in Symfony 8')]
    #[Override]
    public function eraseCredentials(): void
    {
    }

    /**
     * @return array{pubkey: string, roles: list<string>}
     */
    // Deliberate: the session carries hex and role names, never a serialised PublicKey — see ADR-0001
    public function __serialize(): array
    {
        return ['pubkey' => $this->pubkey->toHex(), 'roles' => $this->roles];
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $pubkeyHex = $data['pubkey'] ?? null;
        $roles = $data['roles'] ?? null;

        if (!is_string($pubkeyHex) || !is_array($roles)) {
            throw new InvalidArgumentException('A serialised user carries a pubkey and roles');
        }

        $roleNames = array_map(
            static fn (mixed $role): string => is_string($role)
                ? $role
                : throw new InvalidArgumentException(sprintf('A serialised role must be a string, got %s', get_debug_type($role))),
            array_values($roles),
        );

        $this->pubkey = PublicKey::tryFromHex($pubkeyHex)
            ?? throw new InvalidArgumentException(sprintf('"%s" is not a public key', $pubkeyHex));
        $this->roles = $roleNames;
    }
}
