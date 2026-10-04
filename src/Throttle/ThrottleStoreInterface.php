<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Throttle;

/**
 * Where a Throttler keeps its counters.
 *
 * A limiter cannot keep its count in the request: PHP gives a request no shared
 * memory, so the count has to sit somewhere both requests reach — Redis, APCu,
 * a file. This interface is that seam, and it is deliberately one method wide.
 *
 * hit() has to be atomic per key: two concurrent requests for the same key must
 * not both read the same count and both write count + 1. A backend that counts
 * and expires in one step (Redis INCR + EXPIRE, APCu) satisfies that; a
 * read-then-write in PHP does not.
 */
interface ThrottleStoreInterface
{
    /**
     * Record one hit against $key and report the window it landed in.
     *
     * The first hit opens the window and is what starts the clock: $key must
     * then expire $windowSeconds later. Later hits count up inside that window
     * and must not extend it — traffic cannot buy a key more time.
     *
     * @param int $windowSeconds window length in seconds; always >= 1
     *
     * @return array{count: int, resetIn: int} count = hits in the window,
     *                                         this one included; resetIn =
     *                                         seconds until the window ends
     */
    public function hit(string $key, int $windowSeconds): array;
}
