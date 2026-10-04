<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Throttle;

use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Throttle\ThrottleGuard;
use MiGears\WebGuards\Throttle\Throttler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ThrottleGuardTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tempDirs = [];
    }

    public function testLetsTheRequestThroughWhileUnderTheLimit(): void
    {
        $guard = new ThrottleGuard(
            new Throttler(new InMemoryThrottleStore(), limit: 1, windowSeconds: 60),
            static fn (Request $request): string => 'ip:' . ($request->server['REMOTE_ADDR'] ?? 'unknown'),
        );

        $this->assertNull($guard(new Request('GET', '/users', server: ['REMOTE_ADDR' => '1.2.3.4'])));
    }

    public function testRefusesWithA429AndRetryAfter(): void
    {
        $store = new InMemoryThrottleStore(static fn (): int => 1_000_000);
        $guard = new ThrottleGuard(
            new Throttler($store, limit: 2, windowSeconds: 45),
            static fn (Request $request): string => 'ip:1',
        );
        $request = new Request('GET', '/users');

        $this->assertNull($guard($request));
        $this->assertNull($guard($request));

        $response = $guard($request);

        $this->assertNotNull($response);
        $this->assertSame(429, $response->status);
        $this->assertSame('45', $response->headers['Retry-After']);
        $this->assertSame('2', $response->headers['X-RateLimit-Limit']);
        $this->assertSame('0', $response->headers['X-RateLimit-Remaining']);
        $this->assertSame('{"error":"Too Many Requests"}', $response->body);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testTheKeyCallableSeesTheRequest(): void
    {
        $seen = null;
        $guard = new ThrottleGuard(
            new Throttler(new InMemoryThrottleStore(), limit: 1, windowSeconds: 60),
            static function (Request $request) use (&$seen): string {
                $seen = $request->path;

                return 'path:' . $request->path;
            },
        );

        $guard(new Request('GET', '/orders/42'));

        $this->assertSame('/orders/42', $seen);
    }

    public function testRegisteredOnMiRestItRefusesBeforeRoutingRuns(): void
    {
        $rest = new MiRest($this->makeTempDir());
        $rest->set(LoggerInterface::class, static fn () => new NullLogger());

        $store = new InMemoryThrottleStore(static fn (): int => 1_000_000);
        $rest->before(new ThrottleGuard(
            new Throttler($store, limit: 1, windowSeconds: 60),
            static fn (Request $request): string => 'ip:' . ($request->server['REMOTE_ADDR'] ?? 'unknown'),
        ));

        $request = static fn (): Request => new Request('GET', '/anything', server: ['REMOTE_ADDR' => '9.9.9.9']);

        $first = $rest->handle($request());
        $this->assertSame(404, $first->status, 'the first request reaches routing, where nothing answers it');

        $second = $rest->handle($request());
        $this->assertSame(429, $second->status, 'the second is refused before routing runs');
        $this->assertSame('60', $second->headers['Retry-After']);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/migears-web-guards-' . bin2hex(random_bytes(6));

        if (!is_dir($dir) && !mkdir($dir) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create a temporary directory at {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }
}
