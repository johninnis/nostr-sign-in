<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Infrastructure\Security;

use Override;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class RefusalResponder implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function __construct(
        private string $scriptedPrefix = '/action/',
    ) {
    }

    #[Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->refusal($request, Response::HTTP_UNAUTHORIZED, 'Sign in to do that.');
    }

    // Deliberate: a refused browser is redirected home rather than shown the status, so a guarded area does not announce itself — see ADR-0002
    #[Override]
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->refusal($request, Response::HTTP_FORBIDDEN, 'That is not yours to do.');
    }

    private function refusal(Request $request, int $status, string $message): Response
    {
        if (!$this->wantsJson($request)) {
            return new RedirectResponse($request->getBasePath().'/');
        }

        $challenge = Response::HTTP_UNAUTHORIZED === $status ? ['WWW-Authenticate' => 'Nostr'] : [];

        return new JsonResponse(['success' => false, 'message' => $message], $status, $challenge);
    }

    private function wantsJson(Request $request): bool
    {
        return 'json' === $request->getPreferredFormat(null)
            || $request->isXmlHttpRequest()
            || str_starts_with($request->getPathInfo(), $this->scriptedPrefix);
    }
}
