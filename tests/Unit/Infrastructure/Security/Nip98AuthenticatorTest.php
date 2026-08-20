<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Infrastructure\Security;

use Innis\Nostr\Core\Application\Service\Nip98ValidatorInterface;
use Innis\Nostr\Core\Domain\Failure\Nip98ValidationFailure;
use Innis\Nostr\Core\Domain\Service\NostrAuthHeaderCodec;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\SignIn\Infrastructure\Security\Nip98Authenticator;
use Innis\Nostr\SignIn\Infrastructure\Security\NostrUser;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class Nip98AuthenticatorTest extends TestCase
{
    private const string PUBKEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    private const string SIGN_IN_URL = 'http://localhost/action/sign-in';

    public function testOnlyTheSignInRouteIsSupported(): void
    {
        $onRoute = self::request();
        $offRoute = Request::create('http://localhost/action/like', 'POST');

        self::assertTrue($this->authenticator(self::verifiedKey())->supports($onRoute));
        self::assertFalse($this->authenticator(self::verifiedKey())->supports($offRoute));
    }

    public function testThePassportCarriesTheClaimedKeyBeforeAnyVerification(): void
    {
        $request = self::request();
        $request->headers->set('Authorization', self::decodableProof());

        $passport = $this->authenticator(self::verifiedKey())->authenticate($request);

        self::assertSame(self::PUBKEY_HEX, $passport->getBadge(UserBadge::class)?->getUserIdentifier());
    }

    public function testARequestCarryingNoProofIsRefused(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->authenticator(self::verifiedKey())->authenticate(self::request());
    }

    public function testAnUnreadableProofIsRefusedAtTheDoor(): void
    {
        $request = self::request();
        $request->headers->set('Authorization', 'Nostr not-base64!');

        $this->expectException(AuthenticationException::class);

        $this->authenticator(self::verifiedKey())->authenticate($request);
    }

    public function testAFailedVerificationRefusesTheCredentialsWithItsMessage(): void
    {
        $request = self::request();
        $request->headers->set('Authorization', self::decodableProof());
        $passport = $this->authenticator(Nip98ValidationFailure::BadSignature)->authenticate($request);

        try {
            $passport->getBadge(CustomCredentials::class)?->executeCustomChecker(self::user());
            self::fail('expected an authentication exception');
        } catch (AuthenticationException $exception) {
            self::assertSame(Nip98ValidationFailure::BadSignature->message(), $exception->getMessageKey());
        }
    }

    public function testAVerifiedProofPassesItsCredentialCheck(): void
    {
        $this->expectNotToPerformAssertions();

        $request = self::request();
        $request->headers->set('Authorization', self::decodableProof());
        $passport = $this->authenticator(self::verifiedKey())->authenticate($request);

        $passport->getBadge(CustomCredentials::class)?->executeCustomChecker(self::user());
    }

    public function testSuccessAnswersWithTheKeyInBothSpellings(): void
    {
        $token = new PostAuthenticationToken(self::user(), 'main', ['ROLE_USER']);

        $response = $this->authenticator(self::verifiedKey())->onAuthenticationSuccess(self::request(), $token, 'main');

        self::assertSame(
            ['success' => true, 'pubkey' => self::PUBKEY_HEX, 'npub' => self::verifiedKey()->toBech32()],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testAHostsOwnUserClassIsAnsweredByItsIdentifier(): void
    {
        $foreignUser = new InMemoryUser(self::PUBKEY_HEX, null);
        $token = new PostAuthenticationToken($foreignUser, 'main', ['ROLE_USER']);

        $response = $this->authenticator(self::verifiedKey())->onAuthenticationSuccess(self::request(), $token, 'main');

        self::assertSame(
            ['success' => true, 'pubkey' => self::PUBKEY_HEX, 'npub' => self::verifiedKey()->toBech32()],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testAProviderMintingNonPubkeyIdentifiersFailsLoudly(): void
    {
        $foreignUser = new InMemoryUser('someone@example.test', null);
        $token = new PostAuthenticationToken($foreignUser, 'main', ['ROLE_USER']);

        $this->expectException(LogicException::class);

        $this->authenticator(self::verifiedKey())->onAuthenticationSuccess(self::request(), $token, 'main');
    }

    public function testFailureAnswersUnauthorisedWithTheWords(): void
    {
        $refusal = new AuthenticationException();

        $response = $this->authenticator(self::verifiedKey())->onAuthenticationFailure(self::request(), $refusal);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringContainsString($refusal->getMessageKey(), (string) $response->getContent());
        self::assertSame('Nostr', $response->headers->get('WWW-Authenticate'));
    }

    public function testAHostsOwnHandlersAnswerInsteadOfTheDefaults(): void
    {
        $successHandler = $this->createStub(AuthenticationSuccessHandlerInterface::class);
        $successHandler->method('onAuthenticationSuccess')->willReturn(new Response('their success'));
        $failureHandler = $this->createStub(AuthenticationFailureHandlerInterface::class);
        $failureHandler->method('onAuthenticationFailure')->willReturn(new Response('their refusal', Response::HTTP_UNAUTHORIZED));
        $validator = $this->createStub(Nip98ValidatorInterface::class);
        $authenticator = new Nip98Authenticator($validator, $successHandler, $failureHandler);
        $token = new PostAuthenticationToken(self::user(), 'main', ['ROLE_USER']);

        self::assertSame('their success', $authenticator->onAuthenticationSuccess(self::request(), $token, 'main')->getContent());
        self::assertSame('their refusal', $authenticator->onAuthenticationFailure(self::request(), new AuthenticationException())->getContent());
    }

    private function authenticator(PublicKey|Nip98ValidationFailure $verdict): Nip98Authenticator
    {
        $validator = $this->createStub(Nip98ValidatorInterface::class);
        $validator->method('validate')->willReturn($verdict);

        return new Nip98Authenticator($validator);
    }

    private static function request(): Request
    {
        $request = Request::create(self::SIGN_IN_URL, 'POST');
        $request->attributes->set('_route', Nip98Authenticator::SIGN_IN_ROUTE);

        return $request;
    }

    private static function decodableProof(): string
    {
        $event = [
            'id' => str_repeat('1', 64),
            'pubkey' => self::PUBKEY_HEX,
            'created_at' => 1_700_000_000,
            'kind' => 27235,
            'tags' => [['u', self::SIGN_IN_URL], ['method', 'POST']],
            'content' => '',
            'sig' => str_repeat('2', 128),
        ];

        return NostrAuthHeaderCodec::HEADER_PREFIX.base64_encode(json_encode($event, JSON_THROW_ON_ERROR));
    }

    private static function user(): NostrUser
    {
        return new NostrUser(self::verifiedKey(), ['ROLE_USER']);
    }

    private static function verifiedKey(): PublicKey
    {
        return PublicKey::tryFromHex(self::PUBKEY_HEX) ?? self::fail('fixture pubkey is not valid hex');
    }
}
