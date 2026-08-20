<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Integration\Support\Infrastructure;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Application\Port\RoleAssignerInterface;
use Innis\Nostr\SignIn\Infrastructure\Authorisation\ConfiguredKeyRoleAssigner;
use Override;

final class TogglingRoleAssigner implements RoleAssignerInterface
{
    private bool $administratorsRevoked = false;

    public function __construct(
        private readonly ConfiguredKeyRoleAssigner $configured,
    ) {
    }

    public function revokeAdministrators(): void
    {
        $this->administratorsRevoked = true;
    }

    #[Override]
    public function rolesFor(PublicKey $pubkey): array
    {
        return $this->administratorsRevoked ? ['ROLE_USER'] : $this->configured->rolesFor($pubkey);
    }
}
