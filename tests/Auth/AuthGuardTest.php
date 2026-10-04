<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Auth;

use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use PHPUnit\Framework\TestCase;

final class AuthGuardTest extends TestCase
{
    public function testLetsAnAuthenticatedRequestThrough(): void
    {
        $guard = new AuthGuard(static fn (Request $request): bool => true);

        $this->assertNull($guard(new Request('GET', '/profile')));
    }

    public function testRefusesWithA401AndABearerChallenge(): void
    {
        $guard = new AuthGuard(static fn (Request $request): bool => false);

        $response = $guard(new Request('GET', '/profile'));

        $this->assertNotNull($response);
        $this->assertSame(401, $response->status);
        $this->assertSame('{"error":"Unauthorized"}', $response->body);
        $this->assertSame('Bearer', $response->headers['WWW-Authenticate']);
    }

    public function testTheAuthenticateCallableSeesTheRequest(): void
    {
        $seen = null;
        $guard = new AuthGuard(static function (Request $request) use (&$seen): bool {
            $seen = $request->header('authorization');

            return $seen === 'Bearer good-token';
        });

        $response = $guard(new Request('GET', '/profile', headers: ['authorization' => 'Bearer good-token']));

        $this->assertNull($response);
        $this->assertSame('Bearer good-token', $seen);
    }

    public function testTheChallengeIsConfigurable(): void
    {
        $guard = new AuthGuard(static fn (Request $request): bool => false, challenge: 'Basic realm="api"');

        $response = $guard(new Request('GET', '/profile'));

        $this->assertNotNull($response);
        $this->assertSame('Basic realm="api"', $response->headers['WWW-Authenticate']);
    }
}
