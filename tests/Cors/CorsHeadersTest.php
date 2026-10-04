<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Cors;

use MiGears\Web\Request;
use MiGears\Web\Response;
use MiGears\WebGuards\Cors\CorsHeaders;
use MiGears\WebGuards\Cors\CorsPolicy;
use PHPUnit\Framework\TestCase;

final class CorsHeadersTest extends TestCase
{
    public function testAddsOriginHeadersToAResponseForAnAllowedOrigin(): void
    {
        $after = new CorsHeaders(new CorsPolicy(['https://app.example.com']));
        $request = new Request('GET', '/orders', headers: ['origin' => 'https://app.example.com']);

        $response = $after($request, new Response('ok', 200));

        $this->assertSame('https://app.example.com', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $response->headers['Vary']);
        $this->assertSame('ok', $response->body, 'the response itself is untouched');
        $this->assertSame(200, $response->status);
    }

    public function testDoesNotAdvertisePreflightOnlyHeadersOnAnActualResponse(): void
    {
        $after = new CorsHeaders(new CorsPolicy(['https://app.example.com'], ['GET', 'POST'], maxAge: 600));
        $request = new Request('GET', '/orders', headers: ['origin' => 'https://app.example.com']);

        $response = $after($request, new Response('ok', 200));

        $this->assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headers);
        $this->assertArrayNotHasKey('Access-Control-Max-Age', $response->headers);
    }

    public function testLeavesTheResponseAloneForADisallowedOrigin(): void
    {
        $after = new CorsHeaders(new CorsPolicy(['https://app.example.com']));
        $request = new Request('GET', '/orders', headers: ['origin' => 'https://evil.example.com']);

        $response = $after($request, new Response('ok', 200));

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function testLeavesTheResponseAloneWithoutAnOrigin(): void
    {
        $after = new CorsHeaders(new CorsPolicy(['*']));

        $response = $after(new Request('GET', '/orders'), new Response('ok', 200));

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function testUsesTheWildcardWhenCredentialsAreNotAllowed(): void
    {
        $after = new CorsHeaders(new CorsPolicy(['*']));
        $request = new Request('GET', '/orders', headers: ['origin' => 'https://anything.example.com']);

        $response = $after($request, new Response('ok', 200));

        $this->assertSame('*', $response->headers['Access-Control-Allow-Origin']);
        $this->assertArrayNotHasKey('Vary', $response->headers);
    }

    public function testEchoesTheOriginWhenCredentialsAreAllowed(): void
    {
        $after = new CorsHeaders(new CorsPolicy(['*'], allowCredentials: true));
        $request = new Request('GET', '/orders', headers: ['origin' => 'https://app.example.com']);

        $response = $after($request, new Response('ok', 200));

        $this->assertSame('https://app.example.com', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $response->headers['Access-Control-Allow-Credentials']);
    }
}
