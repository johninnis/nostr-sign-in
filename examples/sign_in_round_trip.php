<?php

declare(strict_types=1);

use Innis\Nostr\Core\Application\Service\Nip98Validator;
use Innis\Nostr\Core\Domain\Service\Nip98EventChecker;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\HttpUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip98Request;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\SignIn\Infrastructure\Authorisation\ConfiguredKeyRoleAssigner;
use Innis\Nostr\SignIn\Infrastructure\Cache\CachePoolNip98ReplayGuard;
use Innis\Nostr\SignIn\Infrastructure\Security\Nip98Authenticator;
use Innis\Nostr\SignIn\Infrastructure\Security\NostrUserProvider;
use Innis\Nostr\SignIn\Testing\SignedAuthHeader;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

require __DIR__.'/../vendor/autoload.php';

$keyPair = SignedAuthHeader::keyPair();
$signInUrl = 'http://localhost/action/sign-in';

$authenticator = new Nip98Authenticator(new Nip98Validator(
    checker: new Nip98EventChecker(SignedAuthHeader::signer()),
    replayGuard: new CachePoolNip98ReplayGuard(new ArrayAdapter()),
    clock: new SystemClock(),
));

$provider = new NostrUserProvider(new ConfiguredKeyRoleAssigner($keyPair->getPublicKey()->toBech32()));

$request = Request::create($signInUrl, 'POST');
$request->attributes->set('_route', Nip98Authenticator::SIGN_IN_ROUTE);
$request->headers->set('Authorization', SignedAuthHeader::forRequest($keyPair, Nip98Request::fromBodyHash(HttpUrl::fromString($signInUrl), 'POST')));

$checkCredentialsAsTheFirewallWould = static function (Passport $passport, UserInterface $user): void {
    $credentials = $passport->getBadge(CustomCredentials::class) ?? throw new RuntimeException('no credentials badge');
    $credentials->executeCustomChecker($user);
};

$passport = $authenticator->authenticate($request);
$identifier = $passport->getBadge(UserBadge::class)?->getUserIdentifier() ?? throw new RuntimeException('no badge');
$user = $provider->loadUserByIdentifier($identifier);
$checkCredentialsAsTheFirewallWould($passport, $user);
$token = new PostAuthenticationToken($user, 'main', $user->getRoles());

echo 'Sign-in response: '.$authenticator->onAuthenticationSuccess($request, $token, 'main')->getContent().PHP_EOL;
echo 'Roles the firewall now carries: '.implode(', ', $user->getRoles()).PHP_EOL;

try {
    $checkCredentialsAsTheFirewallWould($authenticator->authenticate($request), $user);
    echo 'Replaying the same proof: unexpectedly accepted'.PHP_EOL;
} catch (AuthenticationException $refused) {
    echo 'Replaying the same proof: '.$authenticator->onAuthenticationFailure($request, $refused)->getContent().PHP_EOL;
}
