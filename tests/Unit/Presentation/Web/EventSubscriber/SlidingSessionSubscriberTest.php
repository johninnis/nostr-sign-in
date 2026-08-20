<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Presentation\Web\EventSubscriber;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\SignIn\Presentation\Web\EventSubscriber\SlidingSessionSubscriber;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SlidingSessionSubscriberTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testAnActiveSessionsCookieIsReIssuedALifetimeFromNow(): void
    {
        $request = self::requestWithStartedSession();
        $event = $this->answering($request, new Response());

        $this->subscriber(['cookie_lifetime' => 3600])->onResponse($event);

        $cookie = self::sessionCookie($event->getResponse(), $request);
        self::assertNotNull($cookie);
        self::assertSame(self::NOW + 3600, $cookie->getExpiresTime());
    }

    public function testAResponseAlreadyCarryingTheCookieIsLeftAlone(): void
    {
        $request = self::requestWithStartedSession();
        $response = new Response();
        $response->headers->setCookie(Cookie::create($request->getSession()->getName(), 'fresh'));
        $event = $this->answering($request, $response);

        $this->subscriber(['cookie_lifetime' => 3600])->onResponse($event);

        self::assertCount(1, $event->getResponse()->headers->getCookies());
    }

    public function testNoConfiguredLifetimeMeansNoSliding(): void
    {
        $event = $this->answering(self::requestWithStartedSession(), new Response());

        $this->subscriber([])->onResponse($event);

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testAVisitorWithoutAStartedSessionGetsNoCookie(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $event = $this->answering($request, new Response());

        $this->subscriber(['cookie_lifetime' => 3600])->onResponse($event);

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testTheCookieCarriesTheConfiguredShape(): void
    {
        $request = self::requestWithStartedSession();
        $event = $this->answering($request, new Response());

        $this->subscriber([
            'cookie_lifetime' => 3600,
            'cookie_path' => '/app',
            'cookie_domain' => 'example.test',
            'cookie_secure' => true,
            'cookie_samesite' => 'lax',
        ])->onResponse($event);

        $cookie = self::sessionCookie($event->getResponse(), $request);
        self::assertNotNull($cookie);
        self::assertSame('/app', $cookie->getPath());
        self::assertSame('example.test', $cookie->getDomain());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    public function testASameSiteValueTheBrowserWouldNotRecogniseIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->subscriber(['cookie_lifetime' => 3600, 'cookie_samesite' => 'lenient']);
    }

    /**
     * @param array{cookie_lifetime?: int|string, cookie_path?: string, cookie_domain?: string, cookie_secure?: bool|string, cookie_httponly?: bool|string, cookie_samesite?: string|null} $sessionOptions
     */
    private function subscriber(array $sessionOptions): SlidingSessionSubscriber
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(self::NOW));

        return new SlidingSessionSubscriber($sessionOptions, $clock);
    }

    private static function requestWithStartedSession(): Request
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $request = new Request();
        $request->setSession($session);

        return $request;
    }

    private function answering(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }

    private static function sessionCookie(Response $response, Request $request): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $request->getSession()->getName()) {
                return $cookie;
            }
        }

        return null;
    }
}
