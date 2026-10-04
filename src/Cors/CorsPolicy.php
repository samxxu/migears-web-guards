<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Cors;

use InvalidArgumentException;

/**
 * The configuration a CorsGuard and its CorsHeaders partner share.
 *
 * It answers two questions and computes the headers for them:
 *
 * - may this Origin call at all?
 * - what Access-Control-* headers go with it?
 *
 * A wildcard origin is emitted literally ('*') only when credentials are off;
 * with credentials the request's own origin is echoed instead, because '*' and
 * credentials are not allowed together. Whenever the origin is echoed rather
 * than wildcarded, a 'Vary: Origin' rides along so a shared cache cannot serve
 * one origin's response to another.
 *
 * Preflight-only headers (methods, headers, max age) are kept apart from the
 * ones an actual response carries, so the after hook never advertises them on a
 * real answer.
 */
final class CorsPolicy
{
    /** @var list<string> */
    private readonly array $allowedOrigins;

    /** @var list<string> */
    private readonly array $allowedMethods;

    /** @var list<string> */
    private readonly array $allowedHeaders;

    /**
     * @param list<string> $allowedOrigins origins that may call, or ['*'] for any
     * @param list<string> $allowedMethods methods advertised on a preflight
     * @param list<string> $allowedHeaders request headers advertised on a preflight
     * @param int $maxAge seconds a preflight may be cached; 0 omits the header
     * @param bool $allowCredentials whether the browser may send credentials
     *
     * @throws InvalidArgumentException when no origin is allowed, a value is empty, or the max age is negative
     */
    public function __construct(
        array $allowedOrigins,
        array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = [],
        private readonly int $maxAge = 0,
        private readonly bool $allowCredentials = false,
    ) {
        if ($allowedOrigins === []) {
            throw new InvalidArgumentException("At least one allowed origin is required (use ['*'] to allow any).");
        }
        if ($maxAge < 0) {
            throw new InvalidArgumentException('The max age must not be negative.');
        }

        $this->allowedOrigins = self::normalize($allowedOrigins, 'origin');
        $this->allowedMethods = self::normalize($allowedMethods, 'method', true);
        $this->allowedHeaders = self::normalize($allowedHeaders, 'header');
    }

    public function allowsOrigin(string $origin): bool
    {
        return in_array('*', $this->allowedOrigins, true)
            || in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * Headers an actual response carries.
     *
     * @return array<string, string>
     */
    public function originHeaders(string $origin): array
    {
        $wildcard = in_array('*', $this->allowedOrigins, true);
        $echoOrigin = !$wildcard || $this->allowCredentials;

        $headers = ['Access-Control-Allow-Origin' => $echoOrigin ? $origin : '*'];
        if ($echoOrigin) {
            $headers['Vary'] = 'Origin';
        }
        if ($this->allowCredentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }

    /**
     * Headers a preflight response carries: the origin ones plus what the
     * preflight is asking about.
     *
     * @return array<string, string>
     */
    public function preflightHeaders(string $origin): array
    {
        $headers = $this->originHeaders($origin);
        $headers['Access-Control-Allow-Methods'] = implode(', ', $this->allowedMethods);

        if ($this->allowedHeaders !== []) {
            $headers['Access-Control-Allow-Headers'] = implode(', ', $this->allowedHeaders);
        }
        if ($this->maxAge > 0) {
            $headers['Access-Control-Max-Age'] = (string) $this->maxAge;
        }

        return $headers;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function normalize(array $values, string $label, bool $upper = false): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                throw new InvalidArgumentException("A CORS {$label} must not be empty.");
            }
            $normalized[] = $upper ? strtoupper($trimmed) : $trimmed;
        }

        return $normalized;
    }
}
