<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Integration;

use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip98Request;
use Innis\Nostr\SignIn\Testing\SignedAuthHeader;
use Innis\Nostr\SignIn\Tests\Integration\Support\Infrastructure\TogglingRoleAssigner;
use Innis\Nostr\SignIn\Tests\Integration\Support\TestKernel;
use Override;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

final class SignInFlowTest extends WebTestCase
{
    private const string SIGN_IN_URL = 'http://localhost/action/sign-in';

    private const string ADMIN_PRIVATE_KEY = '0000000000000000000000000000000000000000000000000000000000000001';

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
    }

    public function testACorrectlySignedProofOpensASessionTheFirewallRemembers(): void
    {
        $client = self::client();
        $keyPair = SignedAuthHeader::keyPair();

        $this->signIn($client, $keyPair);

        self::assertResponseIsSuccessful();
        self::assertSame($keyPair->getPublicKey()->toHex(), self::payload($client)['pubkey']);
        self::assertSame($keyPair->getPublicKey()->toBech32(), self::payload($client)['npub']);

        $client->request('GET', '/action/probe/identity');

        self::assertSame($keyPair->getPublicKey()->toHex(), self::payload($client)['pubkey']);
    }

    public function testARequestCarryingNoProofIsRefused(): void
    {
        $client = self::client();

        $client->request('POST', '/action/sign-in');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('That request carried no proof of a key.', self::payload($client)['message']);
    }

    public function testAProofSignedForAnotherUrlIsRefused(): void
    {
        $client = self::client();
        $header = SignedAuthHeader::forRequest(SignedAuthHeader::keyPair(), Nip98Request::fromBodyHash('http://localhost/action/probe/identity', 'POST'));

        $client->request('POST', '/action/sign-in', server: ['HTTP_AUTHORIZATION' => $header]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAReplayedProofIsRefused(): void
    {
        $client = self::client();
        $client->disableReboot();
        $header = self::signInProof(SignedAuthHeader::keyPair());

        $client->request('POST', '/action/sign-in', server: ['HTTP_AUTHORIZATION' => $header]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/action/sign-in', server: ['HTTP_AUTHORIZATION' => $header]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testACrossSiteWriteIsRefusedBeforeTheFirewallSeesIt(): void
    {
        $client = self::client();

        $client->request('POST', '/action/sign-in', server: [
            'HTTP_AUTHORIZATION' => self::signInProof(SignedAuthHeader::keyPair()),
            'HTTP_ORIGIN' => 'https://elsewhere.example',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('That request did not come from this site.', self::payload($client)['message']);
    }

    public function testASameOriginWritePassesTheOriginCheck(): void
    {
        $client = self::client();
        $keyPair = SignedAuthHeader::keyPair();

        $client->request('POST', '/action/sign-in', server: [
            'HTTP_AUTHORIZATION' => self::signInProof($keyPair),
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($keyPair->getPublicKey()->toHex(), self::payload($client)['pubkey']);
    }

    public function testRepeatedFailingProofsAreThrottled(): void
    {
        $client = self::client();
        $client->disableReboot();
        $keyPair = SignedAuthHeader::keyPair();
        $wrongUrl = Nip98Request::fromBodyHash('http://localhost/action/probe/identity', 'POST');

        $client->request('POST', '/action/sign-in', server: [
            'HTTP_AUTHORIZATION' => SignedAuthHeader::forRequest($keyPair, $wrongUrl),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('POST', '/action/sign-in', server: [
            'HTTP_AUTHORIZATION' => SignedAuthHeader::forRequest($keyPair, $wrongUrl),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('POST', '/action/sign-in', server: [
            'HTTP_AUTHORIZATION' => SignedAuthHeader::forRequest($keyPair, $wrongUrl),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $message = self::payload($client)['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Too many failed login attempts', $message);
    }

    public function testNobodyReachingARequiredKeyIsAskedToSignIn(): void
    {
        $client = self::client();

        $client->request('GET', '/action/probe/required');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('Sign in to do that.', self::payload($client)['message']);
    }

    public function testTheConfiguredKeyHoldsRoleAdmin(): void
    {
        $client = self::client();
        $admin = SignedAuthHeader::keyPairFromPrivateKeyHex(self::ADMIN_PRIVATE_KEY);

        $this->signIn($client, $admin);
        $client->request('GET', '/action/probe/admin');

        self::assertResponseIsSuccessful();
        self::assertSame($admin->getPublicKey()->toHex(), self::payload($client)['admin']);
    }

    public function testEveryOtherKeyIsRefusedTheAdminProbe(): void
    {
        $client = self::client();

        $this->signIn($client, SignedAuthHeader::keyPair());
        $client->request('GET', '/action/probe/admin');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('That is not yours to do.', self::payload($client)['message']);
    }

    public function testABrowserRefusedAGuardedPageIsBouncedHome(): void
    {
        $client = self::client();

        $client->request('GET', '/probe/page', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseRedirects('/');
    }

    public function testTheSessionIdIsReplacedOnEverySignIn(): void
    {
        $client = self::client();

        $this->signIn($client, SignedAuthHeader::keyPair());
        $firstSessionId = self::sessionId($client);

        $keyPair = SignedAuthHeader::keyPair();
        $this->signIn($client, $keyPair);

        self::assertNotSame($firstSessionId, self::sessionId($client));

        $client->request('GET', '/action/probe/identity');
        self::assertSame($keyPair->getPublicKey()->toHex(), self::payload($client)['pubkey']);
    }

    public function testAnActiveSessionsCookieSlidesRatherThanExpiringMidVisit(): void
    {
        $client = self::client();

        $this->signIn($client, SignedAuthHeader::keyPair());
        $client->request('GET', '/action/probe/identity');

        $cookie = self::responseSessionCookie($client);
        self::assertSame(self::sessionId($client), $cookie->getValue());
        self::assertGreaterThan(time() + 7000, $cookie->getExpiresTime());
    }

    public function testAVisitorWithoutASessionCookieIsNeverGivenOne(): void
    {
        $client = self::client();

        $client->request('GET', '/action/probe/identity');

        self::assertSame(['pubkey' => null], self::payload($client));
        self::assertNull($client->getResponse()->headers->get('Set-Cookie'));
    }

    public function testARevokedRoleDeauthenticatesTheSessionOnItsNextRequest(): void
    {
        $client = self::client();
        $client->disableReboot();
        $admin = SignedAuthHeader::keyPairFromPrivateKeyHex(self::ADMIN_PRIVATE_KEY);

        $this->signIn($client, $admin);
        $client->request('GET', '/action/probe/admin');
        self::assertResponseIsSuccessful();

        $assigner = static::getContainer()->get(TogglingRoleAssigner::class);
        self::assertInstanceOf(TogglingRoleAssigner::class, $assigner);
        $assigner->revokeAdministrators();

        $client->request('GET', '/action/probe/identity');
        self::assertSame(['pubkey' => null], self::payload($client));
    }

    public function testSigningOutForgetsWhoWasSignedIn(): void
    {
        $client = self::client();

        $this->signIn($client, SignedAuthHeader::keyPair());
        $client->request('POST', '/action/sign-out');

        self::assertResponseIsSuccessful();
        self::assertSame(['success' => true, 'pubkey' => null], self::payload($client));

        $client->request('GET', '/action/probe/identity');

        self::assertSame(['pubkey' => null], self::payload($client));
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel();
    }

    private static function client(): KernelBrowser
    {
        return static::createClient();
    }

    private function signIn(KernelBrowser $client, KeyPair $keyPair): void
    {
        $client->request('POST', '/action/sign-in', server: [
            'HTTP_AUTHORIZATION' => self::signInProof($keyPair),
        ]);
    }

    private static function signInProof(KeyPair $keyPair): string
    {
        return SignedAuthHeader::forRequest($keyPair, Nip98Request::fromBodyHash(self::SIGN_IN_URL, 'POST'));
    }

    private static function sessionId(KernelBrowser $client): string
    {
        $cookie = $client->getCookieJar()->get('MOCKSESSID');
        self::assertNotNull($cookie);

        return $cookie->getValue();
    }

    private static function responseSessionCookie(KernelBrowser $client): Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ('MOCKSESSID' === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('The response carried no session cookie.');
    }

    /**
     * @return array<mixed>
     */
    private static function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
