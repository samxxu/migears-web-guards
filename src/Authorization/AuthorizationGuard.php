<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Authorization;

use Closure;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * An authorization before() hook.
 *
 * Authentication says who the caller is; this says what they may do, and refuses
 * with a 403 when they may not. The decision is the caller's policy — a role, a
 * permission, an ownership check on the route parameter — so it arrives as a
 * callable that answers yes or no.
 *
 * It stands on its own: an unauthenticated caller that fails the policy gets a
 * 403, not a 401, so register AuthGuard first when the two are used together.
 * Nothing is shared between the guards — the policy callable resolves whatever
 * it needs from the request.
 */
final class AuthorizationGuard
{
    /** @var Closure(Request): bool */
    private readonly Closure $authorize;

    /**
     * @param callable(Request): bool $authorize true when the request may proceed
     */
    public function __construct(callable $authorize)
    {
        $this->authorize = Closure::fromCallable($authorize);
    }

    public function __invoke(Request $request): ?Response
    {
        if (($this->authorize)($request)) {
            return null;
        }

        return Response::json(['error' => 'Forbidden'], 403);
    }
}
