<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\Throttle;

use Closure;
use MiGears\WebGuards\Throttle\ThrottleStoreInterface;

/**
 * A ThrottleStoreInterface for the test suite.
 *
 * It is a test double, not a deployment option: PHP gives a request no shared
 * memory, so counters in here vanish with the process. It keeps each key's
 * window end so a test can drive time forward through the injected clock and
 * watch a window close.
 */
final class InMemoryThrottleStore implements ThrottleStoreInterface
{
    /** @var array<string, int> hits per key */
    private array $counts = [];

    /** @var array<string, int> absolute expiry per key */
    private array $expiresAt = [];

    public function __construct(private readonly ?Closure $clock = null)
    {
    }

    public function hit(string $key, int $windowSeconds): array
    {
        $now = $this->now();

        if (isset($this->expiresAt[$key]) && $this->expiresAt[$key] <= $now) {
            unset($this->counts[$key], $this->expiresAt[$key]);
        }

        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $this->expiresAt[$key] ??= $now + $windowSeconds;

        return [
            'count' => $this->counts[$key],
            'resetIn' => max(0, $this->expiresAt[$key] - $now),
        ];
    }

    /**
     * How many hits the key currently holds — for assertions.
     */
    public function countOf(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }
}
