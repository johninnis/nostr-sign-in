<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Presentation\Web\EventSubscriber;

use Innis\Nostr\SignIn\Presentation\Web\EventSubscriber\SameOriginActionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SameOriginActionSubscriberTest extends TestCase
{
    public function testACrossSitePostToAnActionIsRefused(): void
    {
        $event = $this->arriving(self::post('/action/like', origin: 'http://evil.example'));

        new SameOriginActionSubscriber()->onRequest($event);

        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testASameOriginPostPasses(): void
    {
        $event = $this->arriving(self::post('/action/like', origin: 'http://localhost'));

        new SameOriginActionSubscriber()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAPostWithoutAnOriginPasses(): void
    {
        $event = $this->arriving(self::post('/action/like'));

        new SameOriginActionSubscriber()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testEveryWriteMethodIsGuarded(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $request = Request::create('http://localhost/action/note', $method);
            $request->headers->set('Origin', 'http://evil.example');
            $event = $this->arriving($request);

            new SameOriginActionSubscriber()->onRequest($event);

            self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
        }
    }

    public function testAReadIsNotGuarded(): void
    {
        $request = Request::create('http://localhost/action/like-status');
        $request->headers->set('Origin', 'http://evil.example');
        $event = $this->arriving($request);

        new SameOriginActionSubscriber()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAPathOutsideTheActionsIsNotGuarded(): void
    {
        $event = $this->arriving(self::post('/newsletter', origin: 'http://evil.example'));

        new SameOriginActionSubscriber()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAHostGuardsThePrefixItChose(): void
    {
        $event = $this->arriving(self::post('/manage/relay', origin: 'http://evil.example'));

        new SameOriginActionSubscriber('/manage/')->onRequest($event);

        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testTheDefaultPrefixIsNotGuardedOnceAnotherIsChosen(): void
    {
        $event = $this->arriving(self::post('/action/like', origin: 'http://evil.example'));

        new SameOriginActionSubscriber('/manage/')->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testASubRequestIsNotGuarded(): void
    {
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            self::post('/action/like', origin: 'http://evil.example'),
            HttpKernelInterface::SUB_REQUEST,
        );

        new SameOriginActionSubscriber()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    private static function post(string $path, ?string $origin = null): Request
    {
        $request = Request::create('http://localhost'.$path, 'POST');

        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }

    private function arriving(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
