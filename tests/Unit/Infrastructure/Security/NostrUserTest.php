<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Infrastructure\Security;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Infrastructure\Security\NostrUser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NostrUserTest extends TestCase
{
    private const string PUBKEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    public function testTheSessionFormCarriesHexAndRoleNamesOnly(): void
    {
        $serialised = self::user()->__serialize();

        self::assertSame(['pubkey' => self::PUBKEY_HEX, 'roles' => ['ROLE_USER', 'ROLE_ADMIN']], $serialised);
    }

    public function testAUserSurvivesTheSessionRoundTrip(): void
    {
        $revived = unserialize(serialize(self::user()));

        self::assertInstanceOf(NostrUser::class, $revived);
        self::assertSame(self::PUBKEY_HEX, $revived->getUserIdentifier());
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $revived->getRoles());
    }

    public function testASessionHoldingSomethingThatIsNotAKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::user()->__unserialize(['pubkey' => 'not-a-key', 'roles' => []]);
    }

    public function testASessionMissingItsShapeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::user()->__unserialize(['roles' => []]);
    }

    public function testASessionHoldingANonStringRoleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::user()->__unserialize(['pubkey' => self::PUBKEY_HEX, 'roles' => ['ROLE_USER', 42]]);
    }

    private static function user(): NostrUser
    {
        $pubkey = PublicKey::tryFromHex(self::PUBKEY_HEX) ?? self::fail('fixture pubkey is not valid hex');

        return new NostrUser($pubkey, ['ROLE_USER', 'ROLE_ADMIN']);
    }
}
