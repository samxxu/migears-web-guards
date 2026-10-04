<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\RequestCheck;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\RequestCheck\PayloadSizeGuard;
use PHPUnit\Framework\TestCase;

final class PayloadSizeGuardTest extends TestCase
{
    public function testLetsASmallBodyThrough(): void
    {
        $guard = new PayloadSizeGuard(maxBytes: 1000);

        $this->assertNull($guard($this->sized('100')));
    }

    public function testLetsABodyAtTheLimitThrough(): void
    {
        $guard = new PayloadSizeGuard(maxBytes: 1000);

        $this->assertNull($guard($this->sized('1000')));
    }

    public function testRefusesWithA413OverTheLimit(): void
    {
        $guard = new PayloadSizeGuard(maxBytes: 1000);

        $response = $guard($this->sized('1001'));

        $this->assertNotNull($response);
        $this->assertSame(413, $response->status);
        $this->assertSame('{"error":"Payload Too Large"}', $response->body);
    }

    public function testLetsARequestWithoutAContentLengthThrough(): void
    {
        $guard = new PayloadSizeGuard(maxBytes: 1000);

        $this->assertNull($guard(new Request('POST', '/orders')));
    }

    public function testLetsANonNumericContentLengthThrough(): void
    {
        $guard = new PayloadSizeGuard(maxBytes: 1000);

        $this->assertNull($guard($this->sized('a-lot')));
    }

    public function testRejectsANonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayloadSizeGuard(0);
    }

    private function sized(string $length): Request
    {
        return new Request('POST', '/orders', headers: ['content-length' => $length]);
    }
}
