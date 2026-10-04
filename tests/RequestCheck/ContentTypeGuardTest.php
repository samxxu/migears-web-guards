<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\RequestCheck;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\RequestCheck\ContentTypeGuard;
use PHPUnit\Framework\TestCase;

final class ContentTypeGuardTest extends TestCase
{
    public function testLetsAMatchingContentTypeThrough(): void
    {
        $guard = new ContentTypeGuard(['application/json']);

        $this->assertNull($guard(new Request('POST', '/orders', headers: ['content-type' => 'application/json'])));
    }

    public function testIgnoresTheCharsetParameter(): void
    {
        $guard = new ContentTypeGuard(['application/json']);

        $request = new Request('POST', '/orders', headers: ['content-type' => 'application/json; charset=utf-8']);

        $this->assertNull($guard($request));
    }

    public function testRefusesWithA415OnAMismatch(): void
    {
        $guard = new ContentTypeGuard(['application/json']);

        $response = $guard(new Request('POST', '/orders', headers: ['content-type' => 'text/plain']));

        $this->assertNotNull($response);
        $this->assertSame(415, $response->status);
        $this->assertSame('{"error":"Unsupported Media Type"}', $response->body);
    }

    public function testRefusesAMissingContentTypeOnABodyMethod(): void
    {
        $guard = new ContentTypeGuard(['application/json']);

        $this->assertNotNull($guard(new Request('POST', '/orders')));
    }

    public function testLetsMethodsWithoutABodyThrough(): void
    {
        $guard = new ContentTypeGuard(['application/json']);

        $this->assertNull($guard(new Request('GET', '/orders')));
    }

    public function testSupportsATypeWildcard(): void
    {
        $guard = new ContentTypeGuard(['application/*']);

        $this->assertNull($guard(new Request('POST', '/orders', headers: ['content-type' => 'application/xml'])));
        $this->assertNotNull($guard(new Request('POST', '/orders', headers: ['content-type' => 'text/plain'])));
    }

    public function testMethodsAreConfigurable(): void
    {
        $guard = new ContentTypeGuard(['application/json'], ['POST']);

        $this->assertNull($guard(new Request('PUT', '/orders')), 'PUT is not protected when only POST is listed');
        $this->assertNotNull($guard(new Request('POST', '/orders')));
    }

    public function testRejectsAnEmptyAllowedList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContentTypeGuard([]);
    }

    public function testRejectsAnEmptyTypeInTheList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContentTypeGuard(['application/json', '  ']);
    }
}
