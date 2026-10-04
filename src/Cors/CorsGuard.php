<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Cors;

use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A CORS-preflight before() hook.
 *
 * A browser sends an OPTIONS request ahead of a cross-origin call, asking the
 * server what it will accept; this guard answers that question with a 204 and
 * the Access-Control-* headers the policy allows, before routing ever runs.
 *
 * Only a preflight is claimed: an OPTIONS request without an Origin is an
 * ordinary OPTIONS, and is left to the resource. A preflight from an origin the
 * policy does not allow is still answered — with 204 and no CORS headers — which
 * is how a browser is told "no" without the application having to know.
 *
 * The headers an *actual* response needs cannot come from a before() hook, which
 * cannot touch a response it did not produce; pair this with CorsHeaders on the
 * after chain for that half.
 */
final class CorsGuard
{
    public function __construct(private readonly CorsPolicy $policy)
    {
    }

    public function __invoke(Request $request): ?Response
    {
        if (strtoupper($request->method) !== 'OPTIONS') {
            return null;
        }

        $origin = $request->header('origin');
        if (!is_string($origin) || $origin === '') {
            // An ordinary OPTIONS, not a preflight.
            return null;
        }

        $response = Response::empty(204);

        if ($this->policy->allowsOrigin($origin)) {
            foreach ($this->policy->preflightHeaders($origin) as $name => $value) {
                $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
