<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Https;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * An HTTPS-enforcement before() hook.
 *
 * A plain-HTTP request is answered with a redirect to the same URL over HTTPS,
 * so a client that arrives on http:// is moved before any handler runs. An
 * already-secure request continues untouched.
 *
 * "Secure" is read from the request: HTTPS, then REQUEST_SCHEME, then port 443.
 * Behind a TLS-terminating proxy none of those are true — the proxy speaks HTTP
 * to the application — so the proxy's X-Forwarded-Proto is consulted, but only
 * when trustForwardedProto is true. That flag is off by default because the
 * header is client-writable; turn it on only behind a proxy that overwrites it.
 *
 * The redirect is 301 by default; 308 is the other useful choice, since it keeps
 * the method and body across the hop.
 */
final class HttpsGuard
{
    /**
     * @param int $status the redirect status, a 3xx code
     * @param bool $trustForwardedProto read X-Forwarded-Proto to detect TLS termination
     *
     * @throws InvalidArgumentException when $status is not a 3xx code
     */
    public function __construct(
        private readonly int $status = 301,
        private readonly bool $trustForwardedProto = false,
    ) {
        if ($status < 300 || $status > 399) {
            throw new InvalidArgumentException('The redirect status must be a 3xx code.');
        }
    }

    public function __invoke(Request $request): ?Response
    {
        if ($this->isSecure($request)) {
            return null;
        }

        $query = $request->query === [] ? '' : '?' . http_build_query($request->query);

        return Response::redirect('https://' . $this->host($request) . $request->path . $query, $this->status);
    }

    private function isSecure(Request $request): bool
    {
        if ($this->trustForwardedProto) {
            $proto = $request->header('x-forwarded-proto');
            if (is_string($proto) && $proto !== '') {
                return strtolower(trim(explode(',', $proto)[0])) === 'https';
            }
        }

        $https = $request->server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $scheme = $request->server['REQUEST_SCHEME'] ?? null;
        if (is_string($scheme)) {
            return strtolower($scheme) === 'https';
        }

        return (string) ($request->server['SERVER_PORT'] ?? '') === '443';
    }

    private function host(Request $request): string
    {
        $host = $request->header('host');
        if (is_string($host) && $host !== '') {
            return $host;
        }

        $name = $request->server['SERVER_NAME'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return 'localhost';
    }
}
