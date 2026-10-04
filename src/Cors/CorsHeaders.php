<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Cors;

use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * The after() half of CORS.
 *
 * CorsGuard answers the preflight, but an actual response has to carry the
 * Access-Control-Allow-Origin header too — and a before() hook cannot touch a
 * response it did not produce. This is the after hook that does: register it with
 *
 *     $rest->after(new CorsHeaders($policy));
 *
 * It adds the origin headers to whatever the resource returned, and leaves the
 * response untouched when the request carried no Origin or the origin is not
 * allowed. It mutates and returns the same response, which is the after chain's
 * contract.
 */
final class CorsHeaders
{
    public function __construct(private readonly CorsPolicy $policy)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $origin = $request->header('origin');
        if (!is_string($origin) || $origin === '' || !$this->policy->allowsOrigin($origin)) {
            return $response;
        }

        foreach ($this->policy->originHeaders($origin) as $name => $value) {
            $response->withHeader($name, $value);
        }

        return $response;
    }
}
