<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Presentation\Web\ArgumentResolver;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Application\Port\CurrentUserInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

final readonly class CurrentPubkeyResolver implements ValueResolverInterface
{
    public function __construct(
        private CurrentUserInterface $currentUser,
    ) {
    }

    /**
     * @return iterable<PublicKey|null>
     */
    // Deliberate: nobody reaching a required key throws the authentication exception, so the firewall's entry point answers 401 — see ADR-0002
    #[Override]
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (PublicKey::class !== $argument->getType()) {
            return [];
        }

        $pubkey = $this->currentUser->pubkey();

        if (null === $pubkey && !$argument->isNullable()) {
            throw new AuthenticationCredentialsNotFoundException('Not signed in.');
        }

        return [$pubkey];
    }
}
