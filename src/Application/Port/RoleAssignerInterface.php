<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Application\Port;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

interface RoleAssignerInterface
{
    /**
     * @return list<string>
     */
    public function rolesFor(PublicKey $pubkey): array;
}
