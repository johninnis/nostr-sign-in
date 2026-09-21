# 9. One NIP-98 proof starts a cookie session, and the firewall carries it

## Status

Accepted

## Context

A web application whose visitors hold nostr keys either demands a signed NIP-98 event with every request, or accepts one proof and carries the identity in an ordinary session cookie. Per-request signing prices every click at a signature — an extension prompt under NIP-07, a relay round trip to a bunker under NIP-46 — and demands a live signer for the whole visit. The cookie avoids that, at the cost of being a *bearer* credential: anyone holding it can act as the visitor, and a browser attaches it to requests another site provoked. This package exists for applications that choose the session; per-request applications need only the validator in `innis/nostr-core`.

## Decision

The browser signs exactly one thing: a NIP-98 auth event for the sign-in URL. `Nip98Authenticator` verifies it through `Nip98ValidatorInterface` on the firewall (ADR-0002), which opens the session; every later request is authenticated by the cookie, and the identity is read back through `CurrentUserInterface` from the token's identifier.

Because the cookie is a bearer credential, the mitigations are part of the design, not optional hardening:

- **The session id is replaced on sign-in** — the firewall's default session-fixation strategy: whatever id the browser arrived with was issued before anyone proved anything.
- **`SameOriginActionSubscriber` refuses a cross-site write — any non-safe method — to the guarded prefix** by comparing the `Origin` header to the request's own scheme and host. `SameSite=Lax` covers the ordinary case, but it is a browser default with known edges, not a rule this application enforces — which is exactly why the guard cannot assume Lax has already stopped the non-POST verbs. A write carrying no `Origin` at all passes, and that is the guard's one open door, taken deliberately: every current browser attaches `Origin` to a cross-site non-safe request (a cross-origin redirect degrades it to the literal `null`, which mismatches and is refused), so an absent header means a non-browser client — one holding no ambient cookie for a third party to ride. A test pins the pass-through so relaxing or tightening it is a recorded decision, not an accident.
- **The auth event is spent once.** The validator's replay guard refuses a second presentation of the same event id; ADR-0003 records the shipped default and its trade.
- **Sign-in itself is origin-checked too**, though it authenticates itself: its signature cannot refuse another site making the visitor sign in as *the attacker*, and the visitor would then act under an identity that silently changed.
- **The session stores the pubkey as hex, never a serialised `PublicKey`.** A session is serialised, and a class in one is a version every later deploy must still unserialise. `NostrUser::__serialize` enforces this mechanically — hex and role names only — and a test pins the wire shape.
- **Nothing is asked of the session for a visitor without one.** Reading a session starts it, and starting one sets a cookie — on every page, for every crawler. The lazy firewall's context listener consults the session only when the request carries a previous session's cookie, so anonymous visitors stay sessionless.

`SlidingSessionSubscriber` re-issues the cookie on responses so an active visitor's session slides rather than expiring mid-visit, using the host's own session options so the cookie's shape is stated once.

## Consequences

- Browsing opens no WebSocket and prompts no signer; a relay is contacted at sign-in and when something is published, never on a page view.
- The mitigations are load-bearing. Do not relax `SameSite`, and do not drop the origin check on the grounds that `SameSite` already covers it.
- A consuming application configures its session cookie `HttpOnly`, `SameSite=Lax` and `Secure` (or `auto`); the package cannot enforce framework configuration and the README states the expectation.
- Sign-in cannot be tested by stubbing a signature: `Testing\SignedAuthHeader` signs real events with real keys through the same validator the application verifies with, and the package's own integration suite drives the whole flow through a real firewall.
- The serialised shape — hex and role names — is part of the package's public contract and will not change outside a major release. PHP gives a failed `__unserialize` no graceful exit (Symfony's context listener swallows only its own unserialisation errors), so a session written in a different shape is unreadable outright; a host crossing such a change rotates `framework.session.name`, which orphans every old cookie into anonymity instead of an error. No session data is ever migrated.
