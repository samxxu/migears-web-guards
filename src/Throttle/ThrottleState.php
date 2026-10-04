<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Throttle;

/**
 * Where one key stands after a hit: how many hits that window holds, against
 * what limit, and when the window ends.
 *
 * Immutable, because it is a reading taken at one moment, not a place to keep
 * state — the count itself lives in the store.
 */
final class ThrottleState
{
    public function __construct(
        public readonly int $count,
        public readonly int $limit,
        public readonly int $resetIn,
    ) {
    }

    /**
     * Whether this hit is allowed.
     *
     * $limit is the number of hits the window permits, so the hit that makes
     * the count equal to the limit is the last allowed one, and the next one is
     * refused.
     */
    public function allowed(): bool
    {
        return $this->count <= $this->limit;
    }

    /**
     * How many more hits the window allows; never negative.
     */
    public function remaining(): int
    {
        return max(0, $this->limit - $this->count);
    }

    /**
     * Seconds until the window ends — what a refused request reports as
     * Retry-After.
     */
    public function retryAfter(): int
    {
        return max(0, $this->resetIn);
    }
}
