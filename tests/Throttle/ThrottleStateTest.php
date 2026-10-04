<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Throttle;

use MiGears\WebGuards\Throttle\ThrottleState;
use PHPUnit\Framework\TestCase;

final class ThrottleStateTest extends TestCase
{
    public function testAllowsUpToAndIncludingTheLimit(): void
    {
        $this->assertTrue((new ThrottleState(1, 3, 60))->allowed());
        $this->assertTrue((new ThrottleState(3, 3, 60))->allowed());
        $this->assertFalse((new ThrottleState(4, 3, 60))->allowed());
    }

    public function testRemainingCountsDownAndStopsAtZero(): void
    {
        $this->assertSame(2, (new ThrottleState(1, 3, 60))->remaining());
        $this->assertSame(0, (new ThrottleState(3, 3, 60))->remaining());
        $this->assertSame(0, (new ThrottleState(9, 3, 60))->remaining());
    }

    public function testRetryAfterNeverGoesNegative(): void
    {
        $this->assertSame(15, (new ThrottleState(4, 3, 15))->retryAfter());
        $this->assertSame(0, (new ThrottleState(4, 3, -1))->retryAfter());
    }

    public function testExposesTheReadingItWasBuiltWith(): void
    {
        $state = new ThrottleState(7, 10, 42);

        $this->assertSame(7, $state->count);
        $this->assertSame(10, $state->limit);
        $this->assertSame(42, $state->resetIn);
    }
}
