<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Canonical;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A canonical-URL before() hook.
 *
 * Two independent normalizations, either of which can be turned on alone:
 *
 * - a canonical host, so example.com and www.example.com are one site rather
 *   than two, and the redirect points every request at the chosen one;
 * - a trailing-slash policy, so '/orders' and '/orders/' do not both answer.
 *
 * Only a request that actually differs is redirected; a canonical request
 * continues untouched, which is what keeps this from looping. The query string
 * is preserved across the hop, and the scheme is left as it arrived.
 *
 * The redirect is 301 by default — the canonical URL is meant to be the one
 * remembered.
 */
final class CanonicalGuard
{
    /**
     * @param ?string $canonicalHost the host to redirect to, or null to leave the host alone
     * @param ?bool $trailingSlash true to require a trailing slash, false to remove it, null to ignore
     * @param int $status the redirect status, a 3xx code
     *
     * @throws InvalidArgumentException when $status is not a 3xx code or the host is empty
     */
    public function __construct(
        private readonly ?string $canonicalHost = null,
        private readonly ?bool $trailingSlash = null,
        private readonly int $status = 301,
    ) {
        if ($status < 300 || $status > 399) {
            throw new InvalidArgumentException('The redirect status must be a 3xx code.');
        }
        if ($canonicalHost !== null && trim($canonicalHost) === '') {
            throw new InvalidArgumentException('The canonical host must not be empty.');
        }
    }

    public function __invoke(Request $request): ?Response
    {
        $host = $this->host($request);
        $hostChanged = $this->canonicalHost !== null && strcasecmp($host, $this->canonicalHost) !== 0;

        $path = $this->canonicalPath($request->path);
        $pathChanged = $path !== $request->path;

        if (!$hostChanged && !$pathChanged) {
            return null;
        }

        $scheme = $this->isSecure($request) ? 'https' : 'http';
        $query = $request->query === [] ? '' : '?' . http_build_query($request->query);

        return Response::redirect(
            $scheme . '://' . ($hostChanged ? $this->canonicalHost : $host) . $path . $query,
            $this->status,
        );
    }

    private function canonicalPath(string $path): string
    {
        // The root already is canonical under either policy.
        if ($path === '/' || $this->trailingSlash === null) {
            return $path;
        }

        $trimmed = rtrim($path, '/');

        return $this->trailingSlash ? $trimmed . '/' : $trimmed;
    }

    private function isSecure(Request $request): bool
    {
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
