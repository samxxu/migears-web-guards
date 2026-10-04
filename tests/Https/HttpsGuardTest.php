<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Https;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\Https\HttpsGuard;
use PHPUnit\Framework\TestCase;

final class HttpsGuardTest extends TestCase
{
    public function testLetsAnAlreadySecureRequestThrough(): void
    {
        $guard = new HttpsGuard();

        $this->assertNull($guard(new Request('GET', '/orders', server: ['HTTPS' => 'on'])));
    }

    public function testRedirectsAnInsecureRequestToHttps(): void
    {
        $guard = new HttpsGuard();

        $response = $guard(new Request('GET', '/orders', headers: ['host' => 'example.com']));

        $this->assertNotNull($response);
        $this->assertSame(301, $response->status);
        $this->assertSame('https://example.com/orders', $response->headers['Location']);
    }

    public function testTheRedirectCarriesTheQueryString(): void
    {
        $guard = new HttpsGuard();

        $response = $guard(new Request('GET', '/orders', query: ['page' => '2'], headers: ['host' => 'example.com']));

        $this->assertNotNull($response);
        $this->assertSame('https://example.com/orders?page=2', $response->headers['Location']);
    }

    public function testFallsBackToTheServerNameForTheHost(): void
    {
        $guard = new HttpsGuard();

        $response = $guard(new Request('GET', '/', server: ['SERVER_NAME' => 'api.example.com', 'SERVER_PORT' => '80']));

        $this->assertNotNull($response);
        $this->assertSame('https://api.example.com/', $response->headers['Location']);
    }

    public function testPortFourFourThreeIsTreatedAsSecure(): void
    {
        $guard = new HttpsGuard();

        $this->assertNull($guard(new Request('GET', '/', server: ['SERVER_PORT' => '443'])));
    }

    public function testForwardedProtoIsOnlyTrustedWhenAsked(): void
    {
        $request = static fn (): Request => new Request('GET', '/orders', headers: [
            'host' => 'example.com',
            'x-forwarded-proto' => 'https',
        ]);

        $this->assertNotNull((new HttpsGuard())($request()), 'untrusted by default: still redirected');
        $this->assertNull((new HttpsGuard(trustForwardedProto: true))($request()), 'trusted: the proxy already did TLS');
    }

    public function testTheStatusIsConfigurable(): void
    {
        $guard = new HttpsGuard(status: 308);

        $response = $guard(new Request('GET', '/orders', headers: ['host' => 'example.com']));

        $this->assertNotNull($response);
        $this->assertSame(308, $response->status);
    }

    public function testRejectsANonRedirectStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpsGuard(status: 200);
    }

    public function testRequestSchemeHttpsIsTreatedAsSecure(): void
    {
        $guard = new HttpsGuard();

        $this->assertNull($guard(new Request('GET', '/', server: ['REQUEST_SCHEME' => 'https'])));
    }

    public function testFallsBackToLocalhostForTheHost(): void
    {
        $guard = new HttpsGuard();

        $response = $guard(new Request('GET', '/orders'));

        $this->assertNotNull($response);
        $this->assertSame('https://localhost/orders', $response->headers['Location']);
    }
}
