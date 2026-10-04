<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Throttle;

use MiGears\DataStructure\DataStructureInterface;
use MiGears\WebGuards\Throttle\DataStructureThrottleStore;
use PHPUnit\Framework\TestCase;

final class DataStructureThrottleStoreTest extends TestCase
{
    public function testTheFirstHitOpensTheWindowAndArmsTheExpiry(): void
    {
        $structure = $this->createMock(DataStructureInterface::class);
        $structure->expects($this->once())
            ->method('expire')
            ->with('throttle:1', 60)
            ->willReturn(true);
        $structure->method('hashIncrBy')->willReturn(1);
        $structure->method('ttl')->willReturn(60);

        $store = new DataStructureThrottleStore($structure);

        $this->assertSame(['count' => 1, 'resetIn' => 60], $store->hit('throttle:1', 60));
    }

    public function testLaterHitsCountUpWithoutRearmingTheExpiry(): void
    {
        $count = 0;
        $structure = $this->createMock(DataStructureInterface::class);
        $structure->expects($this->once())->method('expire')->willReturn(true);
        $structure->method('hashIncrBy')->willReturnCallback(static function () use (&$count): int {
            return ++$count;
        });
        $structure->method('ttl')->willReturn(30);

        $store = new DataStructureThrottleStore($structure);

        $this->assertSame(1, $store->hit('throttle:1', 60)['count']);
        $this->assertSame(['count' => 2, 'resetIn' => 30], $store->hit('throttle:1', 60));
    }

    public function testNoUsableTtlFallsBackToAFullWindow(): void
    {
        foreach ([-2, -1] as $ttl) {
            $structure = $this->createMock(DataStructureInterface::class);
            $structure->method('hashIncrBy')->willReturn(1);
            $structure->method('ttl')->willReturn($ttl);
            $structure->method('expire')->willReturn(true);

            $store = new DataStructureThrottleStore($structure);

            $this->assertSame(
                60,
                $store->hit('throttle:1', 60)['resetIn'],
                "a ttl of {$ttl} should fall back to a full window",
            );
        }
    }
}
