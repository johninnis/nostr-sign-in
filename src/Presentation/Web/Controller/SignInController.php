<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Presentation\Web\Controller;

use Innis\Nostr\SignIn\Infrastructure\Security\Nip98Authenticator;
use Innis\Nostr\SignIn\Infrastructure\Security\SignOutResponder;
use LogicException;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

// Deliberate: both actions are unreachable — the firewall answers these routes; a body running means the routes sit outside it — see ADR-0002
#[AsController]
final readonly class SignInController
{
    #[Route('/sign-in', name: Nip98Authenticator::SIGN_IN_ROUTE, methods: ['POST'])]
    public function signIn(): never
    {
        throw new LogicException('Sign-in is answered by Nip98Authenticator; this route is outside the firewall.');
    }

    #[Route('/sign-out', name: SignOutResponder::SIGN_OUT_ROUTE, methods: ['POST'])]
    public function signOut(): never
    {
        throw new LogicException('Sign-out is answered by the firewall logout; this route is outside it, or logout is not configured.');
    }
}
