<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Infrastructure\Cache;

use Innis\Nostr\Core\Application\Port\Nip98ReplayGuardInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Override;
use Psr\Cache\CacheItemPoolInterface;

final readonly class CachePoolNip98ReplayGuard implements Nip98ReplayGuardInterface
{
    private const string KEY_PREFIX = 'nip98_spent_';

    public function __construct(
        private CacheItemPoolInterface $pool,
    ) {
    }

    // Deliberate: check-then-write, because PSR-6 has no atomic add; the window this leaves is recorded, not denied — see ADR-0003
    #[Override]
    public function recordOnce(EventId $eventId, int $ttlSeconds): bool
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$eventId->toHex());

        if ($item->isHit()) {
            return false;
        }

        $item->set(true);
        $item->expiresAfter($ttlSeconds);

        return $this->pool->save($item);
    }
}
