<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Infrastructure\Authorisation;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Infrastructure\Authorisation\ConfiguredKeyRoleAssigner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfiguredKeyRoleAssignerTest extends TestCase
{
    private const string ADMIN_NPUB = 'npub10xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqpkge6d';
    private const string ADMIN_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private const string OTHER_HEX = 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5';

    public function testTheConfiguredKeyIsAnAdministrator(): void
    {
        $roles = new ConfiguredKeyRoleAssigner(self::ADMIN_NPUB)->rolesFor(self::key(self::ADMIN_HEX));

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $roles);
    }

    public function testEveryOtherKeyIsOnlyAUser(): void
    {
        $roles = new ConfiguredKeyRoleAssigner(self::ADMIN_NPUB)->rolesFor(self::key(self::OTHER_HEX));

        self::assertSame(['ROLE_USER'], $roles);
    }

    public function testSeveralAdministratorsMayBeConfigured(): void
    {
        $assigner = new ConfiguredKeyRoleAssigner(self::ADMIN_NPUB.', '.self::OTHER_HEX);

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $assigner->rolesFor(self::key(self::ADMIN_HEX)));
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $assigner->rolesFor(self::key(self::OTHER_HEX)));
    }

    public function testTheKeyMayBeConfiguredAsHex(): void
    {
        $roles = new ConfiguredKeyRoleAssigner(self::ADMIN_HEX)->rolesFor(self::key(self::ADMIN_HEX));

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $roles);
    }

    public function testWithNothingConfiguredNobodyIsAnAdministrator(): void
    {
        $roles = new ConfiguredKeyRoleAssigner('')->rolesFor(self::key(self::ADMIN_HEX));

        self::assertSame(['ROLE_USER'], $roles);
    }

    public function testBlankEntriesAreTreatedAsAbsent(): void
    {
        $roles = new ConfiguredKeyRoleAssigner(' , ')->rolesFor(self::key(self::ADMIN_HEX));

        self::assertSame(['ROLE_USER'], $roles);
    }

    public function testAConfiguredValueThatIsNotAKeyIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConfiguredKeyRoleAssigner(self::ADMIN_NPUB.',not-a-key');
    }

    private static function key(string $hex): PublicKey
    {
        return PublicKey::tryFromHex($hex) ?? self::fail('fixture pubkey is not valid hex');
    }
}
