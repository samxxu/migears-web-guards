<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Maintenance;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\Maintenance\MaintenanceGuard;
use PHPUnit\Framework\TestCase;

final class MaintenanceGuardTest extends TestCase
{
    public function testLetsRequestsThroughWhenMaintenanceIsOff(): void
    {
        $guard = new MaintenanceGuard(static fn (): bool => false);

        $this->assertNull($guard(new Request('GET', '/orders')));
    }

    public function testRefusesWithA503AndRetryAfterWhenMaintenanceIsOn(): void
    {
        $guard = new MaintenanceGuard(static fn (): bool => true);

        $response = $guard(new Request('GET', '/orders'));

        $this->assertNotNull($response);
        $this->assertSame(503, $response->status);
        $this->assertSame('{"error":"Service Unavailable"}', $response->body);
        $this->assertSame('3600', $response->headers['Retry-After']);
    }

    public function testTheBypassLetsSomeRequestsThrough(): void
    {
        $guard = new MaintenanceGuard(
            static fn (): bool => true,
            static fn (Request $request): bool => $request->header('x-internal') === 'yes',
        );

        $this->assertNull($guard(new Request('GET', '/status', headers: ['x-internal' => 'yes'])));
        $this->assertNotNull($guard(new Request('GET', '/orders')));
    }

    public function testRetryAfterIsConfigurable(): void
    {
        $guard = new MaintenanceGuard(static fn (): bool => true, retryAfterSeconds: 120);

        $response = $guard(new Request('GET', '/orders'));

        $this->assertNotNull($response);
        $this->assertSame('120', $response->headers['Retry-After']);
    }

    public function testRejectsANonPositiveRetryAfter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaintenanceGuard(static fn (): bool => true, retryAfterSeconds: 0);
    }
}
