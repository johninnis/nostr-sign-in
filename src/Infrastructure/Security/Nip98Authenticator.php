<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Infrastructure\Security;

use Innis\Nostr\Core\Application\Service\Nip98ValidatorInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Failure\AuthHeaderDecodeFailure;
use Innis\Nostr\Core\Domain\Failure\Nip98ValidationFailure;
use Innis\Nostr\Core\Domain\Service\NostrAuthHeaderCodec;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip98Request;
use LogicException;
use Override;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final readonly class Nip98Authenticator implements AuthenticatorInterface
{
    public const string SIGN_IN_ROUTE = 'nostr_sign_in';

    // Deliberate: optional handlers are the same seam Symfony's AccessTokenAuthenticator exposes; the JSON contract is the default, not a cage — see ADR-0002
    public function __construct(
        private Nip98ValidatorInterface $nip98Validator,
        private ?AuthenticationSuccessHandlerInterface $successHandler = null,
        private ?AuthenticationFailureHandlerInterface $failureHandler = null,
    ) {
    }

    // Deliberate: matched by route name, not path, so the host's route prefix needs no restating here — see ADR-0002
    #[Override]
    public function supports(Request $request): bool
    {
        return self::SIGN_IN_ROUTE === $request->attributes->get('_route');
    }

    // Deliberate: the passport carries the claimed key and defers verification to its credentials, so login throttling counts failed proofs — see ADR-0002
    #[Override]
    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization')
            ?? throw new CustomUserMessageAuthenticationException('That request carried no proof of a key.');

        $event = NostrAuthHeaderCodec::decode($header);

        if ($event instanceof AuthHeaderDecodeFailure) {
            throw new CustomUserMessageAuthenticationException($event->message());
        }

        return new Passport(
            new UserBadge($event->getPubkey()->toHex()),
            new CustomCredentials(
                fn (mixed $proof): bool => $proof instanceof Event && $this->verifiedProof($proof, $request),
                $event,
            ),
        );
    }

    #[Override]
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return new PostAuthenticationToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
    }

    // Deliberate: the pubkey is read off the token identifier, not a user class, so a host may resolve its own users — see ADR-0002
    #[Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $answered = $this->successHandler?->onAuthenticationSuccess($request, $token);

        if (null !== $answered) {
            return $answered;
        }

        $pubkey = PublicKey::tryFromHex($token->getUserIdentifier())
            ?? throw new LogicException(sprintf('The user identifier "%s" is not a public key; a provider on this firewall must identify users by pubkey hex', $token->getUserIdentifier()));

        return new JsonResponse([
            'success' => true,
            'pubkey' => $pubkey->toHex(),
            'npub' => $pubkey->toBech32(),
        ]);
    }

    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->failureHandler?->onAuthenticationFailure($request, $exception)
            ?? new JsonResponse(
                ['success' => false, 'message' => $exception->getMessageKey(), 'pubkey' => null],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => NostrAuthHeaderCodec::SCHEME],
            );
    }

    private function verifiedProof(Event $event, Request $request): bool
    {
        $verified = $this->nip98Validator->validate($event, Nip98Request::fromBody(
            $request->getUri(),
            $request->getMethod(),
            $request->getContent(),
        ));

        if ($verified instanceof Nip98ValidationFailure) {
            throw new CustomUserMessageAuthenticationException($verified->message());
        }

        return true;
    }
}
