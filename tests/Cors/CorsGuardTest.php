<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Cors;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\Cors\CorsGuard;
use MiGears\WebGuards\Cors\CorsPolicy;
use PHPUnit\Framework\TestCase;

final class CorsGuardTest extends TestCase
{
    public function testAnswersAPreflightWithCorsHeaders(): void
    {
        $policy = new CorsPolicy(
            ['https://app.example.com'],
            ['GET', 'POST'],
            ['Content-Type'],
            maxAge: 600,
        );
        $guard = new CorsGuard($policy);

        $request = new Request('OPTIONS', '/orders', headers: [
            'origin' => 'https://app.example.com',
            'access-control-request-method' => 'POST',
        ]);

        $response = $guard($request);

        $this->assertNotNull($response);
        $this->assertSame(204, $response->status);
        $this->assertSame('https://app.example.com', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $response->headers['Vary']);
        $this->assertSame('GET, POST', $response->headers['Access-Control-Allow-Methods']);
        $this->assertSame('Content-Type', $response->headers['Access-Control-Allow-Headers']);
        $this->assertSame('600', $response->headers['Access-Control-Max-Age']);
    }

    public function testAnswersAPreflightFromADisallowedOriginWithoutCorsHeaders(): void
    {
        $guard = new CorsGuard(new CorsPolicy(['https://app.example.com']));

        $request = new Request('OPTIONS', '/orders', headers: ['origin' => 'https://evil.example.com']);

        $response = $guard($request);

        $this->assertNotNull($response);
        $this->assertSame(204, $response->status);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function testLetsANonOptionsRequestThrough(): void
    {
        $guard = new CorsGuard(new CorsPolicy(['https://app.example.com']));

        $this->assertNull($guard(new Request('POST', '/orders', headers: ['origin' => 'https://app.example.com'])));
    }

    public function testLetsAPlainOptionsThrough(): void
    {
        $guard = new CorsGuard(new CorsPolicy(['https://app.example.com']));

        $this->assertNull($guard(new Request('OPTIONS', '/orders')), 'no Origin means it is not a preflight');
    }

    public function testRejectsAnEmptyOriginList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsPolicy([]);
    }

    public function testRejectsANegativeMaxAge(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsPolicy(['*'], maxAge: -1);
    }

    public function testRejectsABlankOrigin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsPolicy(['  ']);
    }
}
