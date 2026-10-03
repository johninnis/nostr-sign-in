# 4. A plain library over Symfony components; consumers bring the firewall

## Status

Accepted

## Context

Two pressures pull the packaging in opposite directions. Toward framework-agnosticism: other applications in this ecosystem serve HTTP without Symfony, and a sign-in package that worked for them too would be shared more widely. Toward a bundle: Symfony's extension point would let the package auto-register its services and routes, so consumers configure nothing.

The framework-agnostic version does not survive contact with the contents. The classes here are made of Symfony types — an authenticator, a user provider, an entry point, kernel-event subscribers, a value resolver. Strip those out and what remains is `innis/nostr-core`'s validator, which already exists and already serves non-Symfony hosts; a portable abstraction over "request", "session" and "firewall" would be a third HTTP interface with adapters on both sides, far larger than what it would share. Non-Symfony servers in this ecosystem also authenticate per request rather than by session (ADR-0001), so they are not consumers of a session package at all.

A bundle earns its machinery when configuration is rich or conditional. Here the wiring is one resource line, a handful of aliases and arguments, one `security.yaml` block and one route import — and the consumers hand-wire every service explicitly precisely so the container holds no magic.

## Decision

The package is a plain library depending on the components its code touches — `http-foundation`, `http-kernel`, `routing`, `event-dispatcher`, `security-core`, `security-http`, `psr/cache` — and never on `framework-bundle`. It ships no bundle and no configuration: a consumer registers the services in its own container, brings `symfony/security-bundle`, and writes its own `security.yaml` naming this package's authenticator, provider, entry point, denied handler and logout route.

**The host owns the URL space.** The controller's route attributes carry only relative paths (`/sign-in`, `/sign-out`); the host's route import mounts them under a prefix of its choosing, and the prefix-watching pieces — the same-origin guard, the refusal renderer's is-this-a-script test — take that prefix as a constructor argument, defaulting to `/action/`.

## Consequences

- A consumer wires the package explicitly; the README's `services.yaml`, `security.yaml` and `routes.yaml` blocks are the whole integration, a copy-paste rather than a framework.
- The package installs anywhere the Symfony HTTP kernel runs, full framework or not; only running a firewall requires `security-bundle`, and that is the consumer's dependency.
- Non-Symfony hosts are out of scope by design; they use `innis/nostr-core` directly with per-request proofs.
- A bundle with semantic configuration remains possible if consumer counts ever justify it; that would be a superseding record, not a drift.
