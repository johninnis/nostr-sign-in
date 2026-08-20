<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Infrastructure\Security;

use Innis\Nostr\SignIn\Infrastructure\Security\RefusalResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class RefusalResponderTest extends TestCase
{
    public function testAScriptedCallerAskedToSignInGetsJsonWithTheTrueStatus(): void
    {
        $response = new RefusalResponder()->start(Request::create('http://localhost/action/like', 'POST'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringContainsString('Sign in to do that.', (string) $response->getContent());
        self::assertSame('Nostr', $response->headers->get('WWW-Authenticate'));
    }

    public function testAScriptedCallerRefusedOutrightGetsForbidden(): void
    {
        $request = Request::create('http://localhost/action/like', 'POST');

        $response = new RefusalResponder()->handle($request, new AccessDeniedException());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('That is not yours to do.', (string) $response->getContent());
    }

    public function testABrowserIsBouncedHomeInsteadOfShownTheStatus(): void
    {
        $browserPage = Request::create('http://localhost/admin');

        self::assertInstanceOf(RedirectResponse::class, new RefusalResponder()->start($browserPage));
        self::assertInstanceOf(RedirectResponse::class, new RefusalResponder()->handle($browserPage, new AccessDeniedException()));
    }

    public function testTheBounceHomeLandsOnTheDeploymentBasePath(): void
    {
        $subdirectoryPage = Request::create('http://localhost/myapp/admin', 'GET', server: [
            'SCRIPT_FILENAME' => '/var/www/myapp/index.php',
            'SCRIPT_NAME' => '/myapp/index.php',
            'PHP_SELF' => '/myapp/index.php',
        ]);

        $response = new RefusalResponder()->start($subdirectoryPage);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/myapp/', $response->getTargetUrl());
    }

    public function testAHostNamesItsOwnScriptedPrefix(): void
    {
        $response = new RefusalResponder('/manage/')->start(Request::create('http://localhost/manage/relay', 'POST'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
