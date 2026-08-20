<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Unit\Presentation\Web\Controller;

use Innis\Nostr\SignIn\Presentation\Web\Controller\SignInController;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SignInControllerTest extends TestCase
{
    public function testSignInReachedOutsideTheFirewallFailsLoudly(): void
    {
        $this->expectException(LogicException::class);

        new SignInController()->signIn();
    }

    public function testSignOutReachedOutsideTheFirewallFailsLoudly(): void
    {
        $this->expectException(LogicException::class);

        new SignInController()->signOut();
    }
}
