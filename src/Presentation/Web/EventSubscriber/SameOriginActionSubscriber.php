<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Presentation\Web\EventSubscriber;

use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SameOriginActionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private string $guardedPrefix = '/action/',
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 100]];
    }

    // Deliberate: this guard is load-bearing beside SameSite=Lax, not belt-and-braces — see ADR-0001
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isGuarded($request) || $this->isSameOrigin($request)) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'success' => false,
            'message' => 'That request did not come from this site.',
        ], Response::HTTP_FORBIDDEN));
    }

    private function isGuarded(Request $request): bool
    {
        return !$request->isMethodSafe()
            && str_starts_with($request->getPathInfo(), $this->guardedPrefix);
    }

    private function isSameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        return null === $origin || $origin === $request->getSchemeAndHttpHost();
    }
}
