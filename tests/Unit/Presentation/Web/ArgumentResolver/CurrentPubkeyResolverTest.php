<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Presentation\Web\ArgumentResolver;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Application\Port\CurrentUserInterface;
use Innis\Nostr\SignIn\Presentation\Web\ArgumentResolver\CurrentPubkeyResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

final class CurrentPubkeyResolverTest extends TestCase
{
    private const string PUBKEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    public function testAnArgumentOfAnotherTypeIsNotResolved(): void
    {
        $resolved = $this->resolverFor(null)->resolve(new Request(), self::argument('string', nullable: false));

        self::assertSame([], [...$resolved]);
    }

    public function testNobodyResolvesToNullForANullableArgument(): void
    {
        $resolved = $this->resolverFor(null)->resolve(new Request(), self::argument(PublicKey::class, nullable: true));

        self::assertSame([null], [...$resolved]);
    }

    public function testNobodyReachingARequiredArgumentIsAskedToSignIn(): void
    {
        $this->expectException(AuthenticationCredentialsNotFoundException::class);

        [...$this->resolverFor(null)->resolve(new Request(), self::argument(PublicKey::class, nullable: false))];
    }

    public function testTheSignedInKeyIsResolved(): void
    {
        $pubkey = PublicKey::tryFromHex(self::PUBKEY_HEX) ?? self::fail('fixture pubkey is not valid hex');

        $resolved = $this->resolverFor($pubkey)->resolve(new Request(), self::argument(PublicKey::class, nullable: false));

        self::assertSame([$pubkey], [...$resolved]);
    }

    private function resolverFor(?PublicKey $current): CurrentPubkeyResolver
    {
        $currentUser = $this->createStub(CurrentUserInterface::class);
        $currentUser->method('pubkey')->willReturn($current);

        return new CurrentPubkeyResolver($currentUser);
    }

    private static function argument(string $type, bool $nullable): ArgumentMetadata
    {
        return new ArgumentMetadata('pubkey', $type, false, false, null, $nullable);
    }
}
