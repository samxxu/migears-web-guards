<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Canonical;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\Canonical\CanonicalGuard;
use PHPUnit\Framework\TestCase;

final class CanonicalGuardTest extends TestCase
{
    public function testLetsARequestOnTheCanonicalHostThrough(): void
    {
        $guard = new CanonicalGuard(canonicalHost: 'example.com');

        $this->assertNull($guard($this->at('example.com', '/orders')));
    }

    public function testRedirectsToTheCanonicalHost(): void
    {
        $guard = new CanonicalGuard(canonicalHost: 'example.com');

        $response = $guard($this->at('www.example.com', '/orders'));

        $this->assertNotNull($response);
        $this->assertSame(301, $response->status);
        $this->assertSame('http://example.com/orders', $response->headers['Location']);
    }

    public function testHostComparisonIsCaseInsensitive(): void
    {
        $guard = new CanonicalGuard(canonicalHost: 'example.com');

        $this->assertNull($guard($this->at('EXAMPLE.com', '/orders')));
    }

    public function testEnforcesATrailingSlash(): void
    {
        $guard = new CanonicalGuard(trailingSlash: true);

        $response = $guard($this->at('example.com', '/orders'));

        $this->assertNotNull($response);
        $this->assertSame('http://example.com/orders/', $response->headers['Location']);
    }

    public function testRemovesATrailingSlash(): void
    {
        $guard = new CanonicalGuard(trailingSlash: false);

        $response = $guard($this->at('example.com', '/orders/'));

        $this->assertNotNull($response);
        $this->assertSame('http://example.com/orders', $response->headers['Location']);
    }

    public function testTrailingSlashIsIgnoredWhenNotConfigured(): void
    {
        $guard = new CanonicalGuard();

        $this->assertNull($guard($this->at('example.com', '/orders/')));
    }

    public function testTheRootIsNeverRedirectedForATrailingSlash(): void
    {
        $guard = new CanonicalGuard(trailingSlash: true);

        $this->assertNull($guard($this->at('example.com', '/')));
    }

    public function testTheQueryStringIsPreserved(): void
    {
        $guard = new CanonicalGuard(canonicalHost: 'example.com');

        $response = $guard(new Request('GET', '/orders', query: ['page' => '2'], headers: ['host' => 'www.example.com']));

        $this->assertNotNull($response);
        $this->assertSame('http://example.com/orders?page=2', $response->headers['Location']);
    }

    public function testRejectsANonRedirectStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CanonicalGuard(status: 200);
    }

    public function testUsesHttpsWhenTheRequestIsSecure(): void
    {
        $guard = new CanonicalGuard(canonicalHost: 'example.com');

        $request = new Request('GET', '/orders', headers: ['host' => 'www.example.com'], server: ['HTTPS' => 'on']);

        $response = $guard($request);
        $this->assertNotNull($response);
        $this->assertSame('https://example.com/orders', $response->headers['Location']);
    }

    public function testUsesTheRequestSchemeToDetectHttps(): void
    {
        $guard = new CanonicalGuard(canonicalHost: 'example.com');

        $request = new Request('GET', '/orders', headers: ['host' => 'www.example.com'], server: ['REQUEST_SCHEME' => 'https']);

        $response = $guard($request);
        $this->assertNotNull($response);
        $this->assertSame('https://example.com/orders', $response->headers['Location']);
    }

    public function testFallsBackToTheServerNameForTheHost(): void
    {
        $guard = new CanonicalGuard(canonicalHost: 'example.com');

        $response = $guard(new Request('GET', '/orders', server: ['SERVER_NAME' => 'www.example.com', 'SERVER_PORT' => '80']));

        $this->assertNotNull($response);
        $this->assertSame('http://example.com/orders', $response->headers['Location']);
    }

    public function testFallsBackToLocalhostForTheHost(): void
    {
        $guard = new CanonicalGuard(trailingSlash: true);

        $response = $guard(new Request('GET', '/orders'));

        $this->assertNotNull($response);
        $this->assertSame('http://localhost/orders/', $response->headers['Location']);
    }

    public function testRejectsABlankCanonicalHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CanonicalGuard(canonicalHost: '  ');
    }

    private function at(string $host, string $path): Request
    {
        return new Request('GET', $path, headers: ['host' => $host]);
    }
}
