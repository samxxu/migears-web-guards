<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Throttle;

use MiGears\DataStructure\DataStructureInterface;

/**
 * A ThrottleStoreInterface over a Redis-compatible structure.
 *
 * The counter is one hash field per key, incremented through the structure's
 * atomic hashIncrBy(), so the store needs no lock of its own and concurrent
 * requests cannot lose a hit. The first hit is what opens the window, and only
 * that hit arms the TTL; later hits just count up, so traffic cannot extend a
 * window.
 *
 * Known gap, stated rather than hidden: INCR and EXPIRE are two round trips, so
 * a crash between them leaves a counter with no expiry, and that key would then
 * be counted forever. Redis has the same gap without a Lua script. Deleting the
 * key is the recovery. When that matters more than the simplicity here, prefer a
 * backend whose increment also arms the expiry.
 */
final class DataStructureThrottleStore implements ThrottleStoreInterface
{
    /** The hash field the count lives in — one structure per throttle key. */
    private const FIELD = 'hits';

    public function __construct(private readonly DataStructureInterface $structure)
    {
    }

    public function hit(string $key, int $windowSeconds): array
    {
        $count = $this->structure->hashIncrBy($key, self::FIELD);

        if ($count === 1) {
            $this->structure->expire($key, $windowSeconds);
        }

        $ttl = $this->structure->ttl($key);

        return [
            'count' => $count,
            // ttl() answers -1 for a key with no expiry and -2 for a missing
            // key; neither should leave a client without a Retry-After, so both
            // fall back to a full window.
            'resetIn' => $ttl > 0 ? $ttl : $windowSeconds,
        ];
    }
}
