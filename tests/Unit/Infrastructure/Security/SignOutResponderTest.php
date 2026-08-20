<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Infrastructure\Security;

use Innis\Nostr\SignIn\Infrastructure\Security\SignOutResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class SignOutResponderTest extends TestCase
{
    public function testSigningOutIsAnsweredWithNobody(): void
    {
        $request = Request::create('http://localhost/action/sign-out', 'POST');
        $request->attributes->set('_route', SignOutResponder::SIGN_OUT_ROUTE);
        $event = new LogoutEvent($request, null);

        new SignOutResponder()->onSignOut($event);

        self::assertSame(
            ['success' => true, 'pubkey' => null],
            json_decode((string) $event->getResponse()?->getContent(), true),
        );
    }

    public function testAnotherLogoutInTheApplicationIsLeftAlone(): void
    {
        $request = Request::create('http://localhost/logout', 'POST');
        $request->attributes->set('_route', 'app_logout');
        $event = new LogoutEvent($request, null);

        new SignOutResponder()->onSignOut($event);

        self::assertNull($event->getResponse());
    }
}
