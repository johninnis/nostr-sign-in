<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Presentation\Web\EventSubscriber;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use InvalidArgumentException;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @phpstan-type SessionOptions array{cookie_lifetime?: int|string, cookie_path?: string, cookie_domain?: string, cookie_secure?: bool|string, cookie_httponly?: bool|string, cookie_samesite?: string|null}
 */
final readonly class SlidingSessionSubscriber implements EventSubscriberInterface
{
    private int $lifetime;

    private string $path;

    private ?string $domain;

    private ?bool $secure;

    private bool $httpOnly;

    /**
     * @var 'lax'|'none'|'strict'|null
     */
    private ?string $sameSite;

    /**
     * @param SessionOptions $sessionOptions
     */
    public function __construct(
        array $sessionOptions,
        private ClockInterface $clock,
    ) {
        $configuredSecure = $sessionOptions['cookie_secure'] ?? 'auto';
        $domain = $sessionOptions['cookie_domain'] ?? '';

        $this->lifetime = (int) ($sessionOptions['cookie_lifetime'] ?? 0);
        $this->path = $sessionOptions['cookie_path'] ?? '/';
        $this->domain = '' === $domain ? null : $domain;
        $this->secure = 'auto' === $configuredSecure ? null : (bool) $configuredSecure;
        $this->httpOnly = (bool) ($sessionOptions['cookie_httponly'] ?? true);
        $this->sameSite = self::sameSite($sessionOptions['cookie_samesite'] ?? '');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->lifetime <= 0) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isUsingASession($request)) {
            return;
        }

        $session = $request->getSession();

        foreach ($event->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $session->getName()) {
                return;
            }
        }

        $event->getResponse()->headers->setCookie(Cookie::create(
            $session->getName(),
            $session->getId(),
            $this->clock->now()->toInt() + $this->lifetime,
            $this->path,
            $this->domain,
            $this->secure ?? $request->isSecure(),
            $this->httpOnly,
            sameSite: $this->sameSite,
        ));
    }

    private function isUsingASession(Request $request): bool
    {
        return $request->hasSession() && $request->getSession()->isStarted();
    }

    /**
     * @return 'lax'|'none'|'strict'|null
     */
    private static function sameSite(string $configured): ?string
    {
        return match (strtolower($configured)) {
            'lax' => Cookie::SAMESITE_LAX,
            'none' => Cookie::SAMESITE_NONE,
            'strict' => Cookie::SAMESITE_STRICT,
            '' => null,
            default => throw new InvalidArgumentException(sprintf('The session option cookie_samesite "%s" is not lax, none or strict', $configured)),
        };
    }
}
