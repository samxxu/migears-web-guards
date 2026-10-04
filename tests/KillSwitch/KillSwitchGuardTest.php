<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\KillSwitch;

use MiGears\Web\Request;
use MiGears\WebGuards\KillSwitch\KillSwitchGuard;
use PHPUnit\Framework\TestCase;

final class KillSwitchGuardTest extends TestCase
{
    public function testLetsRequestsThroughWhenNotTripped(): void
    {
        $guard = new KillSwitchGuard(static fn (Request $request): bool => false);

        $this->assertNull($guard(new Request('GET', '/orders')));
    }

    public function testRefusesWithA503WhenTripped(): void
    {
        $guard = new KillSwitchGuard(static fn (Request $request): bool => true);

        $response = $guard(new Request('GET', '/orders'));

        $this->assertNotNull($response);
        $this->assertSame(503, $response->status);
        $this->assertSame('{"error":"Service Unavailable"}', $response->body);
    }

    public function testTheTrippedCallableSeesTheRequest(): void
    {
        // One feature off, everything else still served.
        $guard = new KillSwitchGuard(static fn (Request $request): bool => $request->path === '/orders');

        $this->assertNotNull($guard(new Request('GET', '/orders')));
        $this->assertNull($guard(new Request('GET', '/users')));
    }

    public function testThereIsNoRetryAfterHeader(): void
    {
        $guard = new KillSwitchGuard(static fn (Request $request): bool => true);

        $response = $guard(new Request('GET', '/orders'));

        $this->assertNotNull($response);
        $this->assertArrayNotHasKey('Retry-After', $response->headers);
    }
}
