<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Throttle;

use InvalidArgumentException;
use MiGears\WebGuards\Throttle\Throttler;
use PHPUnit\Framework\TestCase;

final class ThrottlerTest extends TestCase
{
    public function testAllowsUpToTheLimitAndRefusesTheNextHit(): void
    {
        $throttler = new Throttler(new InMemoryThrottleStore(), limit: 3, windowSeconds: 60);

        $this->assertTrue($throttler->hit('ip:1')->allowed());
        $this->assertTrue($throttler->hit('ip:1')->allowed());
        $this->assertTrue($throttler->hit('ip:1')->allowed());
        $this->assertFalse($throttler->hit('ip:1')->allowed());
        $this->assertFalse($throttler->hit('ip:1')->allowed());
    }

    public function testStateReportsCountLimitAndWindow(): void
    {
        $throttler = new Throttler(new InMemoryThrottleStore(), limit: 5, windowSeconds: 30);

        $state = $throttler->hit('ip:1');

        $this->assertSame(1, $state->count);
        $this->assertSame(5, $state->limit);
        $this->assertSame(30, $state->resetIn);
        $this->assertSame(4, $state->remaining());
    }

    public function testRemainingFallsToZeroPastTheLimit(): void
    {
        $throttler = new Throttler(new InMemoryThrottleStore(), limit: 1, windowSeconds: 60);

        $this->assertSame(0, $throttler->hit('ip:1')->remaining());
        $this->assertSame(0, $throttler->hit('ip:1')->remaining());
    }

    public function testKeysAreCountedSeparately(): void
    {
        $throttler = new Throttler(new InMemoryThrottleStore(), limit: 1, windowSeconds: 60);

        $this->assertTrue($throttler->hit('ip:1')->allowed());
        $this->assertFalse($throttler->hit('ip:1')->allowed());
        $this->assertTrue($throttler->hit('ip:2')->allowed());
    }

    public function testWindowResetsOnceItExpires(): void
    {
        $now = 1_000_000;
        $store = new InMemoryThrottleStore(static function () use (&$now): int {
            return $now;
        });
        $throttler = new Throttler($store, limit: 1, windowSeconds: 60);

        $this->assertTrue($throttler->hit('ip:1')->allowed());
        $this->assertFalse($throttler->hit('ip:1')->allowed());

        $now += 60;

        $state = $throttler->hit('ip:1');
        $this->assertTrue($state->allowed(), 'the window closed, so the key starts fresh');
        $this->assertSame(1, $state->count);
        $this->assertSame(60, $state->resetIn);
    }

    public function testTrafficDoesNotExtendTheWindow(): void
    {
        $now = 1_000_000;
        $store = new InMemoryThrottleStore(static function () use (&$now): int {
            return $now;
        });
        $throttler = new Throttler($store, limit: 10, windowSeconds: 60);

        $throttler->hit('ip:1');
        $now += 50;

        $state = $throttler->hit('ip:1');

        $this->assertSame(2, $state->count);
        $this->assertSame(10, $state->resetIn, 'the window still ends 60s after the first hit');
    }

    public function testDefaultsToSixtyHitsPerMinute(): void
    {
        $throttler = new Throttler(new InMemoryThrottleStore());

        for ($i = 0; $i < 60; $i++) {
            $this->assertTrue($throttler->hit('ip:1')->allowed(), "hit $i should be allowed");
        }

        $state = $throttler->hit('ip:1');
        $this->assertFalse($state->allowed());
        $this->assertSame(60, $state->limit);
        $this->assertSame(60, $state->resetIn);
    }

    public function testRejectsALimitBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Throttler(new InMemoryThrottleStore(), limit: 0, windowSeconds: 60);
    }

    public function testRejectsAWindowBelowOneSecond(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Throttler(new InMemoryThrottleStore(), limit: 1, windowSeconds: 0);
    }

    public function testRejectsAnEmptyKey(): void
    {
        $throttler = new Throttler(new InMemoryThrottleStore(), limit: 1, windowSeconds: 60);

        $this->expectException(InvalidArgumentException::class);

        $throttler->hit('');
    }
}
