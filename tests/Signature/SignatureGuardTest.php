<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Signature;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\Signature\SignatureGuard;
use PHPUnit\Framework\TestCase;

final class SignatureGuardTest extends TestCase
{
    private const SECRET = 'top-secret';

    public function testLetsAValidSignatureThrough(): void
    {
        $guard = $this->guardAt(1_000_000, 'GET /orders');

        $this->assertNull($guard($this->signedRequest(1_000_000, 'GET /orders')));
    }

    public function testRefusesAMissingSignature(): void
    {
        $guard = $this->guardAt(1_000_000, 'GET /orders');

        $response = $guard(new Request('GET', '/orders', headers: ['x-timestamp' => '1000000']));

        $this->assertNotNull($response);
        $this->assertSame(401, $response->status);
        $this->assertSame('{"error":"Unauthorized"}', $response->body);
        $this->assertSame('HMAC', $response->headers['WWW-Authenticate']);
    }

    public function testRefusesANonNumericTimestamp(): void
    {
        $guard = $this->guardAt(1_000_000, 'GET /orders');

        $signature = hash_hmac('sha256', 'soon.GET /orders', self::SECRET);
        $request = new Request('GET', '/orders', headers: ['x-timestamp' => 'soon', 'x-signature' => $signature]);

        $this->assertNotNull($guard($request));
    }

    public function testRefusesAStaleTimestamp(): void
    {
        $guard = $this->guardAt(1_000_000, 'GET /orders', toleranceSeconds: 300);

        // 301 seconds old: just past the tolerance.
        $this->assertNotNull($guard($this->signedRequest(1_000_000 - 301, 'GET /orders')));
    }

    public function testAcceptsATimestampAtTheEdgeOfTolerance(): void
    {
        $guard = $this->guardAt(1_000_000, 'GET /orders', toleranceSeconds: 300);

        $this->assertNull($guard($this->signedRequest(1_000_000 - 300, 'GET /orders')));
    }

    public function testRefusesAWrongSignature(): void
    {
        $guard = $this->guardAt(1_000_000, 'GET /orders');

        $signature = hash_hmac('sha256', '1000000.GET /orders', 'the-wrong-secret');
        $request = new Request('GET', '/orders', headers: ['x-timestamp' => '1000000', 'x-signature' => $signature]);

        $this->assertNotNull($guard($request));
    }

    public function testThePayloadCallableSeesTheRequest(): void
    {
        $seen = null;
        $guard = new SignatureGuard(
            self::SECRET,
            static function (Request $request) use (&$seen): string {
                $seen = $request->method . ' ' . $request->path;

                return $seen;
            },
            clock: static fn (): int => 1_000_000,
        );

        $guard($this->signedRequest(1_000_000, 'GET /orders'));

        $this->assertSame('GET /orders', $seen);
    }

    public function testTheAlgorithmAndHeaderNamesAreConfigurable(): void
    {
        $guard = new SignatureGuard(
            self::SECRET,
            static fn (Request $request): string => 'payload',
            algorithm: 'sha512',
            signatureHeader: 'X-Hub-Signature',
            timestampHeader: 'X-Hub-Timestamp',
            clock: static fn (): int => 1_000_000,
        );

        $signature = hash_hmac('sha512', '1000000.payload', self::SECRET);
        $request = new Request('GET', '/', headers: ['x-hub-timestamp' => '1000000', 'x-hub-signature' => $signature]);

        $this->assertNull($guard($request));
    }

    public function testRejectsAnEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SignatureGuard('', static fn (Request $request): string => 'payload');
    }

    public function testRejectsAnUnknownAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SignatureGuard(self::SECRET, static fn (Request $request): string => 'payload', algorithm: 'md5-imaginary');
    }

    public function testRejectsANonPositiveTolerance(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SignatureGuard(self::SECRET, static fn (Request $request): string => 'payload', toleranceSeconds: 0);
    }

    public function testRejectsAnEmptyHeaderName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SignatureGuard(self::SECRET, static fn (Request $request): string => 'payload', signatureHeader: '');
    }

    private function guardAt(int $now, string $payload, int $toleranceSeconds = 300): SignatureGuard
    {
        return new SignatureGuard(
            self::SECRET,
            static fn (Request $request): string => $payload,
            toleranceSeconds: $toleranceSeconds,
            clock: static fn (): int => $now,
        );
    }

    private function signedRequest(int $timestamp, string $payload): Request
    {
        return new Request('GET', '/orders', headers: [
            'x-timestamp' => (string) $timestamp,
            'x-signature' => hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET),
        ]);
    }
}
