<?php

declare(strict_types=1);

namespace MiGears\WebGuards\KillSwitch;

use Closure;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A kill-switch before() hook.
 *
 * When the switch is tripped, matching requests are refused with a 503 before
 * they reach a handler — the emergency stop for one feature that is burning the
 * database, returning bad data, or being abused, without a deploy to turn it off.
 *
 * The switch is a callable over the request, so the same guard serves one flag
 * ("everything off") or many ("this endpoint only"): the caller decides what
 * 'tripped' means for the request in front of it.
 *
 * There is no Retry-After, on purpose: a kill switch reports that the feature is
 * off, not when it will be back. That is what MaintenanceGuard is for.
 */
final class KillSwitchGuard
{
    /** @var Closure(Request): bool */
    private readonly Closure $tripped;

    /**
     * @param callable(Request): bool $tripped true when this request should be refused
     */
    public function __construct(callable $tripped)
    {
        $this->tripped = Closure::fromCallable($tripped);
    }

    public function __invoke(Request $request): ?Response
    {
        if (!($this->tripped)($request)) {
            return null;
        }

        return Response::json(['error' => 'Service Unavailable'], 503);
    }
}
