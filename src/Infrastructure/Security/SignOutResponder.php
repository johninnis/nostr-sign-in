<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Infrastructure\Security;

use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class SignOutResponder implements EventSubscriberInterface
{
    public const string SIGN_OUT_ROUTE = 'nostr_sign_out';

    /**
     * @return array<string, string>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onSignOut'];
    }

    public function onSignOut(LogoutEvent $event): void
    {
        if (self::SIGN_OUT_ROUTE !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $event->setResponse(new JsonResponse(['success' => true, 'pubkey' => null]));
    }
}
