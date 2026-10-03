<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Testing;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\Service\NostrAuthHeaderCodec;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip98Request;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use InvalidArgumentException;

final class SignedAuthHeader
{
    private static ?SignatureServiceInterface $signer = null;

    private function __construct()
    {
    }

    public static function signer(): SignatureServiceInterface
    {
        return self::$signer ??= Secp256k1Signer::create();
    }

    public static function keyPair(): KeyPair
    {
        return KeyPair::generate(self::signer());
    }

    public static function keyPairFromPrivateKeyHex(string $hex): KeyPair
    {
        $privateKey = PrivateKey::tryFromHex($hex)
            ?? throw new InvalidArgumentException(sprintf('"%s" is not a private key', $hex));

        return KeyPair::fromPrivateKey($privateKey, self::signer());
    }

    public static function forRequest(KeyPair $keyPair, Nip98Request $request, ?Timestamp $createdAt = null): string
    {
        return NostrAuthHeaderCodec::encode(self::event($keyPair, $request, $createdAt))
            ?? throw new InvalidArgumentException(sprintf('An Authorization header for %s is longer than the %d characters a server reads', (string) $request->getUrl(), NostrAuthHeaderCodec::MAX_HEADER_LENGTH));
    }

    public static function event(KeyPair $keyPair, Nip98Request $request, ?Timestamp $createdAt = null): Event
    {
        $rumour = new RumourFactory($keyPair->getPublicKey())->createHttpAuth($request);

        return (null === $createdAt ? $rumour : $rumour->withCreatedAt($createdAt))->sign($keyPair, self::signer());
    }
}
