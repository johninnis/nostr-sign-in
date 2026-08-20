# 3. A cache-pool replay guard is the shipped default; hard single-spend stays the host's

## Status

Accepted

## Context

The single-spend rule of ADR-0001 needs storage: a spent auth event's id must be recorded so
a second presentation is refused, for as long as the validator's timestamp tolerance could
accept the event. `innis/nostr-core` defines the seam — `Nip98ReplayGuardInterface`, one
method, `recordOnce(EventId, int $ttlSeconds): bool` — and hosts differ in what should back
it: one owns a SQLite database where a unique-key `INSERT` decides atomicity, another holds
state in a long-lived process, a third has only its cache.

Shipping nothing keeps the package storage-agnostic but makes the quick start a wall:
nothing works until the adopter has designed storage for a table they did not know they
needed. There is one storage every Symfony application already has — a PSR-6 cache pool
(`cache.app`) — and a guard over it needs no schema, no migration and no new service. What
it cannot offer is atomicity: PSR-6 has no insert-if-absent, so a cache-backed `recordOnce`
is a check followed by a write, and the gap between those two is precisely where a replay
fits. Pretending otherwise would be worse than shipping nothing.

## Decision

`Nip98ReplayGuardInterface` remains the seam and the host may always supply its own
implementation; this package owns no schema and no database. It ships one default,
`CachePoolNip98ReplayGuard`, over any PSR-6 pool.

The default's single-spend is **best effort**: two presentations of the same event racing
within the check-to-write window — milliseconds, against an attacker who must already hold
a stolen header that expires within the validator's tolerance — can both pass. That trade
is recorded here and at the call site, not hidden.

A host that wants the hard guarantee implements the port against storage with an atomic
insert — a unique-key `INSERT` in SQL, `SET NX` in Redis. `recordOnce` in such an
implementation must be atomic: a read followed by a write re-opens the very window this
port exists to close.

## Consequences

- The README quick start works on any stock Symfony application: alias the port to the
  shipped guard and `cache.app` satisfies its constructor.
- The default is honest about its window. Do not "fix" the check-then-write by adding a
  lock component dependency here; a host needing hard single-spend has a better tool in its
  own storage.
- The cache pool's eviction policy bounds the guard: an evicted entry re-opens a spent
  event id within the timestamp tolerance. `cache.app` defaults are fine; a size-capped
  memory cache would not be.
- The integration suite and the example run on the shipped guard, so the default is what
  the package proves end to end.
