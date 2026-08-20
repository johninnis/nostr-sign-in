# 2. Authentication and authorisation ride Symfony Security

## Status

Accepted

## Context

The temptation for a package this small is to enforce everything itself: an attribute per
guarded route, a subscriber per attribute, an exception family for refusals. That is less
apparatus than a firewall — until the consumers multiply. A public package's adopters are
not one site with one administrator: a discussion group wants admins *of the group*, a
magazine wants editors *of the magazine* — per-object permissions a hand-rolled
one-key-equality mechanism cannot express. Meeting that with more bespoke machinery means
maintaining security code, teaching every adopter a private vocabulary, and re-implementing
— with fewer eyes — what Symfony's Security component already provides: roles, voters,
`#[IsGranted]`, `access_control`, firewalls, session-fixation protection, and CSRF.

## Decision

**The package provides identity; Symfony Security provides enforcement.**

- **`Nip98Authenticator`** is the one genuinely nostr-specific piece: it answers the sign-in
  route (matched by route name, so the host's prefix needs no restating), decodes the
  NIP-98 `Authorization` header, and issues a passport whose user identifier is the
  *claimed* pubkey hex, deferring verification through `Nip98ValidatorInterface` to the
  passport's credentials. That ordering is what lets `login_throttling` count failed proofs
  per claimed key and per IP before any signature work. Success answers
  `{success, pubkey, npub}`; failure answers 401 with the validator's words. The firewall
  replaces the session id on login through its default session-fixation strategy.
- **Identity is read off the token identifier, never a user class.** The success response,
  `CurrentUserInterface` and the `PublicKey` argument resolver all derive the pubkey from
  `getUserIdentifier()`, so a host may point the firewall at its own provider and resolve
  its own users — the one contract being that such a user's identifier is the pubkey hex. A
  provider minting anything else fails loudly at sign-in; a token identified by something
  else (another firewall's users) reads as *nobody* to the nostr side.
- **`NostrUser`** is the shipped user — a pubkey plus roles — and **`NostrUserProvider`**
  mints one from the identifier, re-reading roles on every request. The token's roles are
  frozen at login, so a changed role set deauthenticates the session on its next request
  rather than downgrading it live: revocation is immediate, and its form is a forced
  re-sign-in under the new roles — a stale session can never keep old powers.
- **Roles come through `RoleAssignerInterface`**, a host-supplied port.
  `ConfiguredKeyRoleAssigner` is the shipped default: a comma-separated list of npubs (or
  hex) becomes `ROLE_ADMIN`, everyone authenticated is `ROLE_USER`, empty grants nobody
  admin, and a non-key value is refused when the service is built. A host with richer needs
  implements the port against its own data — and expresses authority *over something* (a
  group, a magazine) as a Symfony voter, which is application domain, never this package's.
- **Refusals are rendered by `RefusalResponder`**, the firewall's entry point (401, "sign
  in") and access-denied handler (403, "not yours"): JSON with the true status to a caller
  that wants JSON, a redirect home for a browser, so a guarded area does not announce
  itself.
- **Sign-out is the firewall's logout**; `SignOutResponder` answers it with
  `{success: true, pubkey: null}` — and only on this package's own sign-out route, so a
  host's other logout keeps its own rendering. The route stubs in `SignInController` exist
  so the router knows the paths; the firewall intercepts both, and a stub actually running
  throws, loudly, because it means the routes sit outside the firewall.

## Consequences

- Hosts run a firewall: `symfony/security-bundle` enabled, a `security.yaml` naming the
  provider, the authenticator, the entry point, the denied handler and logout. The
  security-critical paths are code Symfony maintains and every Symfony developer knows.
- Multiple administrators are configuration; per-object authority is a voter in the
  consuming application. This package will not grow an authorisation vocabulary beyond the
  role port.
- A firewall has exactly one entry point, so a host with an existing login either gives the
  nostr surface its own firewall pattern or writes a small delegating entry point; the
  README spells this out.
- The framework's login machinery composes untouched: `login_throttling` counts failed
  proofs, `user_checker` refuses banned keys at sign-in, a provider throwing
  `UserNotFoundException` from `refreshUser` revokes a key mid-session, and
  `LoginSuccessEvent` is where a host provisions or links accounts on first sign-in.
- A host needing a different wire shape injects its own success and failure handlers, the
  optional constructor seam Symfony's `AccessTokenAuthenticator` exposes; the JSON contract
  is the default. The 401s carry `WWW-Authenticate: Nostr`, naming the scheme a client
  should present.
- The redirect-home refusal hides guarded areas from browsers at the price of not showing
  an honest 403 page; a host wanting one replaces `RefusalResponder`.
