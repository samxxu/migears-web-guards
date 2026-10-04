<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Csrf;

use Closure;
use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A CSRF before() hook for state-changing requests.
 *
 * Only methods that can change state are verified — GET, HEAD and OPTIONS are
 * expected to be safe and pass through untouched. Everything else runs the
 * verifier, and a false answer is refused with a 403.
 *
 * The verifier is the caller's policy: compare a form field, an 'X-CSRF-Token'
 * header, or a double-submit cookie against the session. The guard only decides
 * *when* to ask, never *how* — which is why it stays usable whatever token
 * scheme the application already has.
 *
 * The protected set defaults to POST, PUT, PATCH and DELETE; method names are
 * normalized to upper case, so 'post' protects the same requests as 'POST'.
 */
final class CsrfGuard
{
    /** @var Closure(Request): bool */
    private readonly Closure $verify;

    /** @var list<string> */
    private readonly array $protectedMethods;

    /**
     * @param callable(Request): bool $verify true when the request carries a valid token
     * @param list<string> $protectedMethods methods to verify; defaults to the mutating ones
     *
     * @throws InvalidArgumentException when a protected method name is empty
     */
    public function __construct(
        callable $verify,
        array $protectedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'],
    ) {
        $this->verify = Closure::fromCallable($verify);

        $methods = [];
        foreach ($protectedMethods as $method) {
            $normalized = strtoupper(trim($method));
            if ($normalized === '') {
                throw new InvalidArgumentException('A protected method name must not be empty.');
            }
            $methods[] = $normalized;
        }
        $this->protectedMethods = $methods;
    }

    public function __invoke(Request $request): ?Response
    {
        if (!in_array(strtoupper($request->method), $this->protectedMethods, true)) {
            return null;
        }

        if (($this->verify)($request)) {
            return null;
        }

        return Response::json(['error' => 'CSRF token mismatch'], 403);
    }
}
