<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Authorization;

use MiGears\Web\Request;
use MiGears\WebGuards\Authorization\AuthorizationGuard;
use PHPUnit\Framework\TestCase;

final class AuthorizationGuardTest extends TestCase
{
    public function testLetsAnAllowedRequestThrough(): void
    {
        $guard = new AuthorizationGuard(static fn (Request $request): bool => true);

        $this->assertNull($guard(new Request('GET', '/admin')));
    }

    public function testRefusesWithA403(): void
    {
        $guard = new AuthorizationGuard(static fn (Request $request): bool => false);

        $response = $guard(new Request('GET', '/admin'));

        $this->assertNotNull($response);
        $this->assertSame(403, $response->status);
        $this->assertSame('{"error":"Forbidden"}', $response->body);
    }

    public function testTheAuthorizeCallableSeesTheRequest(): void
    {
        $seen = null;
        $guard = new AuthorizationGuard(static function (Request $request) use (&$seen): bool {
            $seen = $request->method;

            return $request->method === 'GET';
        });

        $this->assertNull($guard(new Request('GET', '/admin')));
        $this->assertSame('GET', $seen);
    }
}
