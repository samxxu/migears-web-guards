<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Maintenance;

use Closure;
use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A maintenance-mode before() hook.
 *
 * While the switch is on, every request is answered with a 503 and a Retry-After
 * saying when to come back — planned downtime, told to the client instead of
 * left as a timeout or a 500. The switch is a callable, so it can read a flag
 * file, a database row or an environment variable, and flip without a deploy.
 *
 * An optional bypass lets some callers through while everyone else is held out —
 * an internal IP range, an admin session, a status endpoint. The bypass answers
 * yes or no per request; nothing about the maintenance state leaks into it.
 *
 * Where KillSwitchGuard trips a single feature off, this closes the door on the
 * whole application for a known length of time, so only this one promises a
 * Retry-After.
 */
final class MaintenanceGuard
{
    /** @var Closure(): bool */
    private readonly Closure $enabled;

    /** @var ?Closure(Request): bool */
    private readonly ?Closure $allowWhen;

    /**
     * @param callable(): bool $enabled true while maintenance is on
     * @param null|callable(Request): bool $allowWhen true to bypass maintenance for this request
     * @param int $retryAfterSeconds seconds reported in Retry-After; >= 1
     *
     * @throws InvalidArgumentException when the retry-after is below 1 second
     */
    public function __construct(
        callable $enabled,
        ?callable $allowWhen = null,
        private readonly int $retryAfterSeconds = 3600,
    ) {
        if ($retryAfterSeconds < 1) {
            throw new InvalidArgumentException('The retry-after must be at least 1 second.');
        }

        $this->enabled = Closure::fromCallable($enabled);
        $this->allowWhen = $allowWhen === null ? null : Closure::fromCallable($allowWhen);
    }

    public function __invoke(Request $request): ?Response
    {
        if (!($this->enabled)()) {
            return null;
        }

        if ($this->allowWhen !== null && ($this->allowWhen)($request)) {
            return null;
        }

        return Response::json(['error' => 'Service Unavailable'], 503)
            ->withHeader('Retry-After', (string) $this->retryAfterSeconds);
    }
}
