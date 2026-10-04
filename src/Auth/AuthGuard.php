<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Auth;

use Closure;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * An authentication before() hook.
 *
 * It answers one question — is this caller authenticated? — and refuses with a
 * 401 when the answer is no. What "authenticated" means is the caller's policy,
 * so it arrives as a callable: a bearer token, a session, a signed cookie, an
 * API key. The guard knows nothing about any of them.
 *
 * Register it before AuthorizationGuard. Authentication says who the caller is,
 * authorization says what they may do, and a 403 only makes sense once a 401
 * would not.
 *
 * A refusal carries a WWW-Authenticate challenge, defaulting to 'Bearer',
 * because a 401 is required to say how to authenticate. Set it to whatever the
 * scheme actually is.
 */
final class AuthGuard
{
    /** @var Closure(Request): bool */
    private readonly Closure $authenticate;

    /**
     * @param callable(Request): bool $authenticate true when the request is authenticated
     * @param string $challenge the WWW-Authenticate challenge sent with a 401
     */
    public function __construct(callable $authenticate, private readonly string $challenge = 'Bearer')
    {
        $this->authenticate = Closure::fromCallable($authenticate);
    }

    public function __invoke(Request $request): ?Response
    {
        if (($this->authenticate)($request)) {
            return null;
        }

        return Response::json(['error' => 'Unauthorized'], 401)
            ->withHeader('WWW-Authenticate', $this->challenge);
    }
}
