<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Throttle;

use InvalidArgumentException;

/**
 * A fixed-window rate limiter.
 *
 * One window per key: the first hit opens it, hits inside it count up, and the
 * window closes $windowSeconds later — which is also what bounds how long one
 * key occupies the store. Fixed windows are chosen over sliding ones on
 * purpose: a counter that keeps no history is readable, and the price is only
 * that a client can burst up to twice the limit across a window boundary. That
 * trade is documented rather than hidden.
 *
 * The Throttler knows nothing about HTTP — it counts and it decides. Turning a
 * refusal into a 429 is ThrottleGuard's job, which leaves this class usable
 * wherever a limit is needed (an RPC method, a job, a CLI command).
 */
final class Throttler
{
    /**
     * @param ThrottleStoreInterface $store where the counters live
     * @param int $limit hits allowed per window; >= 1
     * @param int $windowSeconds window length in seconds; >= 1
     *
     * @throws InvalidArgumentException when the limit or the window is below 1
     */
    public function __construct(
        private readonly ThrottleStoreInterface $store,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('The limit must be at least 1.');
        }
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('The window must be at least 1 second.');
        }
    }

    /**
     * Count one hit against $key and report where that leaves the window.
     *
     * @throws InvalidArgumentException when the key is empty
     */
    public function hit(string $key): ThrottleState
    {
        if ($key === '') {
            throw new InvalidArgumentException('A throttle key must not be empty.');
        }

        $window = $this->store->hit($key, $this->windowSeconds);

        return new ThrottleState($window['count'], $this->limit, $window['resetIn']);
    }
}
