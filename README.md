# innis/nostr-sign-in

NIP-98 cookie-session sign-in for Symfony applications, riding Symfony Security. The browser
signs exactly one [NIP-98](https://github.com/nostr-protocol/nips/blob/master/98.md) proof; a
custom authenticator verifies it and the firewall carries the identity in the session. Roles,
`#[IsGranted]`, `access_control`, voters and CSRF are all Symfony's — this package provides
the nostr-specific identity pieces and nothing the framework already maintains.

A plain library over Symfony components — not a bundle, never dependent on `framework-bundle`;
the consumer brings `symfony/security-bundle` and its own firewall. Applications that
authenticate per request instead of per session need only
[`innis/nostr-core`](https://github.com/johninnis/nostr-core), not this package.

Read `docs/adr/` before judging or changing the design; the decisions and their costs are
recorded there.

## Install

```sh
composer require innis/nostr-sign-in symfony/security-bundle
```

## What the package provides

- **`Nip98Authenticator`** — answers the sign-in route (matched by route name, so your prefix
  needs no restating). Its passport carries the *claimed* key and defers verification
  through `innis/nostr-core`'s `Nip98ValidatorInterface` to the passport's credentials —
  the ordering that lets `login_throttling` count failed proofs. Success answers
  `{success, pubkey, npub}`; failure answers 401 (`WWW-Authenticate: Nostr`) with the
  refusal's own words — the validator's for a failed proof, the codec's for a header that
  did not decode.
- **`NostrUser` / `NostrUserProvider`** — a user is a pubkey plus roles, re-read on every
  request. A role change deauthenticates the session on its next request — the token's
  roles are frozen at login, so a stale session can never keep old powers; the visitor
  signs in again under the new roles.
- **`RoleAssignerInterface`** — where roles come from. The shipped
  `ConfiguredKeyRoleAssigner` maps a comma-separated list of npubs (or hex keys) to
  `ROLE_ADMIN` and grants everyone `ROLE_USER`; empty configuration grants nobody admin, and a
  value that is not a key is refused when the container builds. Implement the port yourself
  for richer schemes — and express authority *over something* (a group's admin, a magazine's
  editor) as a Symfony voter in your application, not as a role here.
- **`RefusalResponder`** — the firewall's entry point (401, "Sign in to do that.") and
  access-denied handler (403, "That is not yours to do."): JSON to a caller that wants JSON, a
  redirect home for a browser, so a guarded area does not announce itself.
- **`SignOutResponder`** — answers the firewall's logout with `{success: true, pubkey: null}`,
  and only on this package's own sign-out route, so an existing logout keeps its rendering.
- **`CachePoolNip98ReplayGuard`** — the shipped replay guard over any PSR-6 pool; see "What
  the host must supply" for its best-effort trade and when to implement the port instead.
- **`SignInController`** — route stubs (`/sign-in`, `/sign-out`, relative; you choose the
  prefix). The firewall intercepts both; a stub actually running throws.
- **`CurrentUserInterface` / `TokenCurrentUser`** — who is acting, for non-controller code
  (Twig, services): the token's pubkey or null, never starting a session for a visitor
  without one.
- **`CurrentPubkeyResolver`** — a controller action takes `PublicKey $pubkey` (nobody is
  asked to sign in, via the entry point) or `?PublicKey $pubkey` (nobody is `null`).
- **`SameOriginActionSubscriber`** — refuses a cross-site write (any non-safe method) to
  your guarded prefix (`/action/` unless configured); the origin-check complement to
  `SameSite=Lax`.
- **`SlidingSessionSubscriber`** — re-issues the session cookie so an active visitor's
  session slides rather than expiring mid-visit.
- **`Testing\SignedAuthHeader`** — signs real proofs for your functional tests: a generated
  (or pinned) key pair and an `Authorization` header for the `Nip98Request` under test, so
  sign-in is tested through the same validator the application verifies with, never by
  stubbing a signature.

## What the host must supply

- **A replay guard — or take the shipped default.** `CachePoolNip98ReplayGuard` works over
  any PSR-6 pool, and every Symfony application already has `cache.app`, so the wiring below
  works as-is. Its single-spend is best effort: PSR-6 has no atomic insert-if-absent, so two
  presentations racing within a milliseconds-wide window can both pass (ADR-0003 records the
  trade). For a hard guarantee, implement
  `Innis\Nostr\Core\Application\Port\Nip98ReplayGuardInterface` against storage with an
  atomic insert — a unique-key `INSERT` in SQL, `SET NX` in Redis — and point the alias at
  yours.
- **Session configuration.** The cookie is a bearer credential; configure it `HttpOnly`,
  `SameSite=Lax` and `Secure` (or `auto`) in `framework.yaml` (ADR-0001). The serialised
  session shape is contract-stable; if you ever change your own user class's serialised
  shape, rotate `framework.session.name` — old cookies then read as anonymous instead of
  erroring, and nothing is migrated.
- **The services.** With `autowire`/`autoconfigure` defaults on, one resource line registers
  everything; only what autowiring cannot guess is stated:

```yaml
services:
    Innis\Nostr\SignIn\:
        resource: '../vendor/innis/nostr-sign-in/src/'
        exclude: '../vendor/innis/nostr-sign-in/src/Testing/'

    Innis\Nostr\SignIn\Application\Port\CurrentUserInterface: '@Innis\Nostr\SignIn\Infrastructure\Identity\TokenCurrentUser'
    Innis\Nostr\SignIn\Application\Port\RoleAssignerInterface: '@Innis\Nostr\SignIn\Infrastructure\Authorisation\ConfiguredKeyRoleAssigner'

    Innis\Nostr\SignIn\Infrastructure\Authorisation\ConfiguredKeyRoleAssigner:
        arguments:
            $administratorNpubs: '%env(ADMIN_NPUBS)%'

    Innis\Nostr\SignIn\Presentation\Web\EventSubscriber\SlidingSessionSubscriber:
        arguments:
            $sessionOptions: '%session.storage.options%'

    Innis\Nostr\Core\Domain\Service\SignatureServiceInterface:
        factory: [Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer, create]
    Innis\Nostr\Core\Infrastructure\Time\SystemClock: ~
    Innis\Nostr\Core\Application\Port\ClockInterface: '@Innis\Nostr\Core\Infrastructure\Time\SystemClock'
    Innis\Nostr\Core\Application\Port\Nip98ReplayGuardInterface: '@Innis\Nostr\SignIn\Infrastructure\Cache\CachePoolNip98ReplayGuard'
    Innis\Nostr\Core\Application\Service\Nip98Validator: ~
    Innis\Nostr\Core\Application\Service\Nip98ValidatorInterface: '@Innis\Nostr\Core\Application\Service\Nip98Validator'
```

- **The firewall**, in `security.yaml`:

```yaml
security:
    providers:
        nostr:
            id: Innis\Nostr\SignIn\Infrastructure\Security\NostrUserProvider
    firewalls:
        main:
            lazy: true
            provider: nostr
            custom_authenticators:
                - Innis\Nostr\SignIn\Infrastructure\Security\Nip98Authenticator
            entry_point: Innis\Nostr\SignIn\Infrastructure\Security\RefusalResponder
            access_denied_handler: Innis\Nostr\SignIn\Infrastructure\Security\RefusalResponder
            login_throttling:
                max_attempts: 5
            logout:
                path: nostr_sign_out
```

`login_throttling` (bring `symfony/security-bundle`'s companion, `composer require
symfony/rate-limiter`) composes with the authenticator by design: the passport carries the
*claimed* key and defers verification to its credentials, so the throttler counts failed
proofs per claimed key and per IP before any signature is checked. A header that does not
even decode never reaches the throttler — there is no identity to count it against.
Symfony's `user_checker` firewall option also composes untouched: implement
`UserCheckerInterface` to refuse a banned or disabled pubkey at sign-in. To revoke a key
mid-session, supply your own provider whose `refreshUser` throws `UserNotFoundException`
for it — the firewall drops the token on the very next request.

- **The routes**, in `routes.yaml` — the paths in the package are relative, so the prefix is
  yours (a browser client must be told the same paths; see `@innis/nostr-signer/session`):

```yaml
nostr_sign_in:
    resource:
        path: ../vendor/innis/nostr-sign-in/src/Presentation/Web/Controller/
        namespace: Innis\Nostr\SignIn\Presentation\Web\Controller
    type: attribute
    prefix: /action
```

The prefix appears in three places that must agree: this route prefix,
`SameOriginActionSubscriber`'s `$guardedPrefix` (which writes are origin-checked), and
`RefusalResponder`'s `$scriptedPrefix` (which callers get JSON refusals instead of a redirect
home). All three default to `/action`; choosing another prefix means configuring the two
constructor arguments beside it.

Then secure whatever needs securing with the framework's own vocabulary:
`#[IsGranted('ROLE_ADMIN')]`, `access_control`, or a voter for per-object questions.

## Alongside an existing login

Nothing in the package demands its own user class. The authenticator identifies users by
**pubkey hex**, so you may point the firewall at your own provider and resolve your own
users — link a nostr key to an existing account — as long as that user's
`getUserIdentifier()` returns the pubkey hex. The success response, `CurrentUserInterface`
and the `PublicKey` argument resolver all read the identifier off the token, never a class.
A token whose identifier is not a pubkey (your form-login users, say) simply reads as
*nobody* to the nostr side. The sign-out responder answers only its own `nostr_sign_out`
route, so an existing logout keeps its own rendering.

**Provisioning and linking on first sign-in** is a listener on Symfony's own
`LoginSuccessEvent` — create your account row, or attach the key to the signed-in person's
existing account, keyed by the pubkey:

```php
final readonly class ProvisionOnFirstSignIn
{
    public function __construct(private MemberStoreInterface $members) {}

    #[AsEventListener]
    public function __invoke(LoginSuccessEvent $event): void
    {
        $this->members->ensureExists($event->getUser()->getUserIdentifier());
    }
}
```

The one thing Symfony will make you choose is the **entry point**: a firewall has exactly
one, so if `form_login` already answers your 401s, either give the nostr surface its own
firewall pattern, or write a small entry point that delegates by path and keep
`RefusalResponder` for the nostr routes. And if your application needs a different wire
shape than `{success, pubkey, npub}`, hand `Nip98Authenticator` your own
`AuthenticationSuccessHandlerInterface` / `AuthenticationFailureHandlerInterface` — the
same optional constructor seam Symfony's own `AccessTokenAuthenticator` exposes.

## Roles from your own data

`ConfiguredKeyRoleAssigner` is the floor, not the mechanism: the provider depends only on
`RoleAssignerInterface`, so a site whose roles are data implements the port against its own
store and re-points one alias line — nothing else changes:

```php
final readonly class SqliteRoleAssigner implements RoleAssignerInterface
{
    public function __construct(private PDO $connection) {}

    #[Override]
    public function rolesFor(PublicKey $pubkey): array
    {
        $statement = $this->connection->prepare('SELECT is_admin, is_editor FROM members WHERE pubkey = ?');
        $statement->execute([$pubkey->toHex()]);
        $row = $statement->fetch();

        return [
            'ROLE_USER',
            ...(false !== $row && $row['is_admin'] ? ['ROLE_ADMIN'] : []),
            ...(false !== $row && $row['is_editor'] ? ['ROLE_EDITOR'] : []),
        ];
    }
}
```

```yaml
Innis\Nostr\SignIn\Application\Port\RoleAssignerInterface: '@App\Infrastructure\Persistence\SqliteRoleAssigner'
```

Two properties to know: roles are re-read on every request (the provider deliberately reloads
through the assigner), and a changed role set deauthenticates the session on its very next
request — Symfony freezes the token's roles at login, so the safe response to a flipped flag
is a forced re-sign-in under the new roles, never a session silently keeping old powers. If
the per-request query ever matters, wrap your assigner in a caching decorator rather than
changing it. Keep the assigner to coarse roles: "is an editor at all" belongs here, "may edit
*this* magazine" belongs in a voter consulting your own store.

## Example

```sh
php examples/sign_in_round_trip.php
```

Signs a real proof with a generated key, authenticates it, shows the roles the firewall would
carry, and shows the same proof being refused on replay.

## Development

```sh
composer test          # phpunit, then phpstan at level 9
composer check-style
composer check-rector
```
