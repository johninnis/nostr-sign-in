<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Integration\Support;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
final readonly class ProbeController
{
    public function identity(?PublicKey $pubkey): JsonResponse
    {
        return new JsonResponse(['pubkey' => $pubkey?->toHex()]);
    }

    public function required(PublicKey $pubkey): JsonResponse
    {
        return new JsonResponse(['pubkey' => $pubkey->toHex()]);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function admin(PublicKey $pubkey): JsonResponse
    {
        return new JsonResponse(['admin' => $pubkey->toHex()]);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function page(): JsonResponse
    {
        return new JsonResponse(['page' => 'guarded']);
    }
}
