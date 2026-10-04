<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Csrf;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\Csrf\CsrfGuard;
use PHPUnit\Framework\TestCase;

final class CsrfGuardTest extends TestCase
{
    public function testLetsSafeMethodsThroughWithoutVerifying(): void
    {
        $verified = false;
        $guard = new CsrfGuard(static function (Request $request) use (&$verified): bool {
            $verified = true;

            return false;
        });

        $this->assertNull($guard(new Request('GET', '/orders')));
        $this->assertNull($guard(new Request('HEAD', '/orders')));
        $this->assertNull($guard(new Request('OPTIONS', '/orders')));
        $this->assertFalse($verified, 'a safe method must not reach the verifier');
    }

    public function testVerifiesTheTokenOnAProtectedMethod(): void
    {
        $guard = new CsrfGuard(static fn (Request $request): bool => $request->header('x-csrf-token') === 'good');

        $this->assertNull($guard(new Request('POST', '/orders', headers: ['x-csrf-token' => 'good'])));
        $this->assertNotNull($guard(new Request('POST', '/orders', headers: ['x-csrf-token' => 'bad'])));
    }

    public function testRefusesWithA403WhenTheTokenIsInvalid(): void
    {
        $guard = new CsrfGuard(static fn (Request $request): bool => false);

        $response = $guard(new Request('DELETE', '/orders/1'));

        $this->assertNotNull($response);
        $this->assertSame(403, $response->status);
        $this->assertSame('{"error":"CSRF token mismatch"}', $response->body);
    }

    public function testProtectedMethodsAreConfigurable(): void
    {
        $guard = new CsrfGuard(static fn (Request $request): bool => false, protectedMethods: ['POST']);

        $this->assertNull($guard(new Request('DELETE', '/orders/1')), 'DELETE is no longer protected');
        $this->assertNotNull($guard(new Request('POST', '/orders')));
    }

    public function testMethodNamesAreNormalizedToUpperCase(): void
    {
        $guard = new CsrfGuard(static fn (Request $request): bool => false, protectedMethods: ['post']);

        $this->assertNotNull($guard(new Request('post', '/orders')));
    }

    public function testRejectsAnEmptyProtectedMethodName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CsrfGuard(static fn (Request $request): bool => true, protectedMethods: ['POST', '  ']);
    }
}
