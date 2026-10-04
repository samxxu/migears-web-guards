<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\TimeWindow;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\TimeWindow\TimeWindowGuard;
use PHPUnit\Framework\TestCase;

final class TimeWindowGuardTest extends TestCase
{
    public function testLetsARequestInsideTheWindowThrough(): void
    {
        $guard = new TimeWindowGuard('09:00', '17:00', $this->at(10, 30));

        $this->assertNull($guard(new Request('GET', '/backoffice')));
    }

    public function testRefusesARequestOutsideTheWindow(): void
    {
        $guard = new TimeWindowGuard('09:00', '17:00', $this->at(8, 0));

        $response = $guard(new Request('GET', '/backoffice'));

        $this->assertNotNull($response);
        $this->assertSame(403, $response->status);
        $this->assertSame('{"error":"Forbidden"}', $response->body);
    }

    public function testTheBoundsAreInclusive(): void
    {
        $guard = new TimeWindowGuard('09:00', '17:00', $this->at(9, 0));
        $this->assertNull($guard(new Request('GET', '/backoffice')), 'the opening minute is inside');

        $guard = new TimeWindowGuard('09:00', '17:00', $this->at(17, 0));
        $this->assertNull($guard(new Request('GET', '/backoffice')), 'the closing minute is inside');
    }

    public function testSupportsAnOvernightWindow(): void
    {
        $this->assertNull((new TimeWindowGuard('22:00', '06:00', $this->at(23, 0)))(new Request('GET', '/jobs')));
        $this->assertNull((new TimeWindowGuard('22:00', '06:00', $this->at(5, 0)))(new Request('GET', '/jobs')));
        $this->assertNotNull((new TimeWindowGuard('22:00', '06:00', $this->at(12, 0)))(new Request('GET', '/jobs')));
    }

    public function testRejectsAMalformedTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeWindowGuard('25:00', '17:00');
    }

    /**
     * @return callable(): int a clock pinned to the given local wall-clock time
     */
    private function at(int $hour, int $minute): callable
    {
        $epoch = mktime($hour, $minute, 0, 1, 15, 2024);

        return static fn (): int => $epoch;
    }
}
