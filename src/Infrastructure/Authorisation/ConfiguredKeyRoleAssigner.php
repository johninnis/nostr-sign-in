<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Infrastructure\Authorisation;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Application\Port\RoleAssignerInterface;
use InvalidArgumentException;
use Override;

final readonly class ConfiguredKeyRoleAssigner implements RoleAssignerInterface
{
    /**
     * @var list<PublicKey>
     */
    private array $administrators;

    // Deliberate: an empty value grants nobody ROLE_ADMIN, a non-key value is refused at construction — see ADR-0002
    public function __construct(string $administratorNpubs = '')
    {
        $administrators = [];

        foreach (explode(',', $administratorNpubs) as $entry) {
            $entry = trim($entry);

            if ('' === $entry) {
                continue;
            }

            $administrators[] = PublicKey::tryFromNpubOrHex($entry)
                ?? throw new InvalidArgumentException(sprintf('The administrator key "%s" is not an npub or a public key', $entry));
        }

        $this->administrators = $administrators;
    }

    #[Override]
    public function rolesFor(PublicKey $pubkey): array
    {
        $isAdministrator = array_any(
            $this->administrators,
            static fn (PublicKey $administrator): bool => $administrator->equals($pubkey),
        );

        return $isAdministrator ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }
}
