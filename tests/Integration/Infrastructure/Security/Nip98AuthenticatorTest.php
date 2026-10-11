<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Integration\Infrastructure\Security;

use Innis\Nostr\Core\Application\Service\Nip98Validator;
use Innis\Nostr\Core\Domain\Failure\Nip98ValidationFailure;
use Innis\Nostr\Core\Domain\Service\Nip98EventChecker;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\HttpUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip98Request;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\SignIn\Infrastructure\Cache\CachePoolNip98ReplayGuard;
use Innis\Nostr\SignIn\Infrastructure\Security\Nip98Authenticator;
use Innis\Nostr\SignIn\Infrastructure\Security\NostrUser;
use Innis\Nostr\SignIn\Testing\SignedAuthHeader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;

final class Nip98AuthenticatorTest extends TestCase
{
    private const string SIGN_IN_URL = 'http://localhost/action/sign-in';

    public function testAProofSignedForTheMethodAsSentPassesItsCredentialCheck(): void
    {
        $keyPair = SignedAuthHeader::keyPair();
        $credentials = self::credentials(self::requestSentAs('patch', self::proofSignedFor($keyPair, 'patch')));

        $credentials->executeCustomChecker(self::user($keyPair));

        self::assertTrue($credentials->isResolved());
    }

    public function testAProofSignedForTheMethodInAnotherCaseIsRefused(): void
    {
        $keyPair = SignedAuthHeader::keyPair();
        $credentials = self::credentials(self::requestSentAs('patch', self::proofSignedFor($keyPair, 'PATCH')));

        $this->expectExceptionMessage(Nip98ValidationFailure::MethodMismatch->message());

        $credentials->executeCustomChecker(self::user($keyPair));
    }

    private static function credentials(Request $request): CustomCredentials
    {
        return self::authenticator()->authenticate($request)->getBadge(CustomCredentials::class)
            ?? self::fail('the passport carried no credentials');
    }

    private static function authenticator(): Nip98Authenticator
    {
        return new Nip98Authenticator(new Nip98Validator(
            new Nip98EventChecker(SignedAuthHeader::signer()),
            new CachePoolNip98ReplayGuard(new ArrayAdapter()),
            new SystemClock(),
        ));
    }

    private static function requestSentAs(string $method, string $proof): Request
    {
        $request = Request::create(self::SIGN_IN_URL);
        $request->setMethod($method);
        $request->headers->set('Authorization', $proof);

        return $request;
    }

    private static function proofSignedFor(KeyPair $keyPair, string $method): string
    {
        return SignedAuthHeader::forRequest($keyPair, Nip98Request::fromBodyHash(HttpUrl::fromString(self::SIGN_IN_URL), $method));
    }

    private static function user(KeyPair $keyPair): NostrUser
    {
        return new NostrUser($keyPair->getPublicKey(), ['ROLE_USER']);
    }
}
