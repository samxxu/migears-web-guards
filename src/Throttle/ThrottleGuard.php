<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Throttle;

use Closure;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A rate-limiting before() hook.
 *
 * It is the callable a MiRest application registers — globally on MiRest or on
 * one resource — and it answers the only two things a before() hook can:
 * continue, or refuse. Allowed, it returns null and dispatch carries on;
 * refused, it returns a 429 carrying Retry-After and stops the request there.
 *
 * Why this is a guard and not a middleware: miGears' before()/after() are a flat
 * hook chain, not a pipeline with next(). A limiter is a pure pre-decision, so it
 * needs before() and never after(), and it cannot wrap anything. Calling it a
 * middleware would promise an onion the framework does not have — so the module
 * is named for what these are, guards.
 *
 * $key decides what is being limited, and that is the caller's policy: a client
 * IP, an authenticated user, an endpoint, or a combination. Namespace it with a
 * prefix of your own ('throttle:') so the keys stay apart in a shared store.
 */
final class ThrottleGuard
{
    /** @var Closure(Request): string */
    private readonly Closure $key;

    /**
     * @param Throttler $throttler the counters and the limit
     * @param callable(Request): string $key maps a request to its throttle key
     */
    public function __construct(
        private readonly Throttler $throttler,
        callable $key,
    ) {
        $this->key = Closure::fromCallable($key);
    }

    /**
     * Register it with $rest->before($guard), or return it from a resource's
     * before(). Null continues; a Response refuses and dispatch stops here.
     */
    public function __invoke(Request $request): ?Response
    {
        $state = $this->throttler->hit(($this->key)($request));

        if ($state->allowed()) {
            return null;
        }

        return Response::json(['error' => 'Too Many Requests'], 429)
            ->withHeader('Retry-After', (string) $state->retryAfter())
            ->withHeader('X-RateLimit-Limit', (string) $state->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $state->remaining());
    }
}
