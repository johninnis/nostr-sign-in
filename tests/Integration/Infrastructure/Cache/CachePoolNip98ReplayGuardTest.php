<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Integration\Infrastructure\Cache;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\SignIn\Infrastructure\Cache\CachePoolNip98ReplayGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachePoolNip98ReplayGuardTest extends TestCase
{
    public function testAFreshEventIsRecorded(): void
    {
        $guard = new CachePoolNip98ReplayGuard(new ArrayAdapter());

        self::assertTrue($guard->recordOnce(self::eventId('a'), 120));
    }

    public function testASpentEventIsRefused(): void
    {
        $guard = new CachePoolNip98ReplayGuard(new ArrayAdapter());
        $guard->recordOnce(self::eventId('a'), 120);

        self::assertFalse($guard->recordOnce(self::eventId('a'), 120));
    }

    public function testEventsSpendIndependently(): void
    {
        $guard = new CachePoolNip98ReplayGuard(new ArrayAdapter());
        $guard->recordOnce(self::eventId('a'), 120);

        self::assertTrue($guard->recordOnce(self::eventId('b'), 120));
    }

    private static function eventId(string $fill): EventId
    {
        return EventId::tryFromHex(str_repeat($fill, 64)) ?? self::fail('fixture event id is not valid hex');
    }
}
