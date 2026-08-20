# Security

This document describes the security properties `innis/nostr-sign-in` provides, the
properties it deliberately does not provide, and where the load-bearing decisions are
recorded. Read it before relying on the package in production; read
[`docs/adr/`](docs/adr/) before changing any code path it describes.

## Audit status

**This package has not undergone an independent third-party security audit.** It is built
and reviewed with care — the whole sign-in flow is integration-tested through a real
Symfony firewall with real signed events, and every non-obvious decision is recorded in
`docs/adr/` — but internal review is not a substitute for an external audit. The
cryptography itself lives in [`innis/nostr-core`](https://github.com/johninnis/nostr-core);
see that package's `SECURITY.md` for its audit status and consumer responsibilities.

## What the package provides

- **One-time, request-bound sign-in proofs.** A NIP-98 event pins the URL and method it was
  signed for, is refused outside the validator's timestamp tolerance, and is spent on first
  presentation (ADR-0001, ADR-0003).
- **A bearer-cookie session with its mitigations wired in**: session-id replacement on
  login via the firewall's fixation strategy, an `Origin` check on guarded writes beside
  `SameSite=Lax`, sessions never started for cookie-less visitors, and session contents
  restricted to the pubkey hex and role names (ADR-0001). The `Origin` check passes a
  write that carries no `Origin` header at all — absence means a non-browser client, since
  every current browser attaches the header to cross-site writes; ADR-0001 records the
  trade.
- **Enforcement by Symfony Security**, not bespoke code: roles, `#[IsGranted]`,
  `access_control`, voters, `login_throttling` and `user_checker` all compose (ADR-0002).

## What the host must do

- **Configure the session cookie** `HttpOnly`, `SameSite=Lax` and `Secure` (or `auto`) —
  the package cannot enforce framework configuration.
- **Choose a replay guard consciously.** The shipped `CachePoolNip98ReplayGuard` is
  best-effort: PSR-6 has no atomic insert-if-absent, so its single-spend has a
  milliseconds-wide race window, and it is bounded by the cache pool's retention. For a
  hard guarantee, implement the port on storage with an atomic insert (ADR-0003).
- **Enable `login_throttling`** on the firewall; the authenticator is shaped so failed
  proofs are counted per claimed key and per IP.
- **Serve over HTTPS behind a correctly configured proxy.** The NIP-98 `u` tag must match
  the URL the application reconstructs, so trusted-proxy and trusted-host configuration is
  part of the security posture, not cosmetics.

## Reporting a vulnerability

Report privately through GitHub's built-in vulnerability reporting: **Security →
Advisories → Report a vulnerability** on the repository page. Do not open a public issue
for security-sensitive bugs.

Include a description and impact, reproduction steps or a proof-of-concept, and the
affected version (tag or commit SHA). Acknowledgement is best-effort within 72 hours.
Fixes land first, then the advisory is published.
