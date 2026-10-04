<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Signature;

use Closure;
use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A request-signature before() hook.
 *
 * It refuses a request unless it carries a valid HMAC over a payload the caller
 * defines, together with a timestamp fresh enough to make a captured signature
 * useless to replay. The signed string is "{timestamp}.{payload}": the timestamp
 * comes first so the payload callable stays free of the replay concern and only
 * has to describe the request itself — method, path, body, whatever the caller
 * decides.
 *
 * A missing, stale or malformed timestamp, and a mismatched signature, all
 * answer the same bare 401: a verifier should not help an attacker tell which
 * part to fix. The comparison uses hash_equals(), so a wrong signature is not
 * told apart by timing.
 *
 * The secret is passed in, and time comes from an injected clock, so a test can
 * drive a replay window forward without sleeping.
 */
final class SignatureGuard
{
    /** @var Closure(Request): string */
    private readonly Closure $payload;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string $secret the shared HMAC secret; must not be empty
     * @param callable(Request): string $payload builds the string to sign
     * @param string $algorithm any algorithm hash_hmac() accepts
     * @param int $toleranceSeconds how far a timestamp may drift, in seconds; >= 1
     * @param string $signatureHeader the header carrying the hex signature
     * @param string $timestampHeader the header carrying the unix timestamp
     * @param null|callable(): int $clock the current unix time; defaults to time()
     *
     * @throws InvalidArgumentException when the secret is empty, the algorithm
     *                                  is unknown, the tolerance is below 1, or
     *                                  a header name is empty
     */
    public function __construct(
        private readonly string $secret,
        callable $payload,
        private readonly string $algorithm = 'sha256',
        private readonly int $toleranceSeconds = 300,
        private readonly string $signatureHeader = 'X-Signature',
        private readonly string $timestampHeader = 'X-Timestamp',
        ?callable $clock = null,
    ) {
        if ($secret === '') {
            throw new InvalidArgumentException('The signing secret must not be empty.');
        }
        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            throw new InvalidArgumentException("Unsupported HMAC algorithm '{$algorithm}'.");
        }
        if ($toleranceSeconds < 1) {
            throw new InvalidArgumentException('The tolerance must be at least 1 second.');
        }
        if ($signatureHeader === '' || $timestampHeader === '') {
            throw new InvalidArgumentException('Header names must not be empty.');
        }

        $this->payload = Closure::fromCallable($payload);
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function __invoke(Request $request): ?Response
    {
        $timestamp = $request->header($this->timestampHeader);
        $signature = $request->header($this->signatureHeader);

        if (!is_string($timestamp) || !ctype_digit($timestamp) || !is_string($signature)) {
            return $this->refuse();
        }

        if (abs(($this->clock)() - (int) $timestamp) > $this->toleranceSeconds) {
            return $this->refuse();
        }

        $expected = hash_hmac($this->algorithm, $timestamp . '.' . ($this->payload)($request), $this->secret);

        if (!hash_equals($expected, $signature)) {
            return $this->refuse();
        }

        return null;
    }

    private function refuse(): Response
    {
        return Response::json(['error' => 'Unauthorized'], 401)
            ->withHeader('WWW-Authenticate', 'HMAC');
    }
}
