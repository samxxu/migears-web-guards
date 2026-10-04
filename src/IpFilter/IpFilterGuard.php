<?php

declare(strict_types=1);

namespace MiGears\WebGuards\IpFilter;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * An IP allow/deny before() hook.
 *
 * Two named constructors spell out the intent: allowing() refuses every client
 * not in the list, blocking() refuses every client in it. Either way the answer
 * comes from the client's IP, matched against CIDR networks so a whole subnet is
 * one entry; a bare address ('10.0.0.7') is just a /32 — or a /128 for IPv6.
 *
 * The client IP is REMOTE_ADDR. Behind a reverse proxy that is the proxy's own
 * address, so normalize REMOTE_ADDR upstream if you need the real client. The
 * guard deliberately does not read X-Forwarded-For on its own: trusting that
 * header without a known trusted-proxy list is just spoofing, and the list of
 * trusted proxies is the caller's to keep.
 *
 * An empty list is honoured literally: allowing() with nothing allows nobody,
 * blocking() with nothing blocks nobody.
 */
final class IpFilterGuard
{
    /**
     * @param list<array{bytes: string, prefix: int}> $networks
     * @param bool $allow true = allowlist, false = denylist
     */
    private function __construct(
        private readonly array $networks,
        private readonly bool $allow,
    ) {
    }

    /**
     * Allow only clients inside $cidrs; everyone else is refused.
     *
     * @throws InvalidArgumentException when a value is not an IP or a CIDR network
     */
    public static function allowing(string ...$cidrs): self
    {
        return new self(self::parseAll($cidrs), true);
    }

    /**
     * Refuse clients inside $cidrs; everyone else continues.
     *
     * @throws InvalidArgumentException when a value is not an IP or a CIDR network
     */
    public static function blocking(string ...$cidrs): self
    {
        return new self(self::parseAll($cidrs), false);
    }

    public function __invoke(Request $request): ?Response
    {
        $ip = $request->server['REMOTE_ADDR'] ?? null;
        $matches = is_string($ip) && $ip !== '' && self::matchesAny($ip, $this->networks);

        // On an allowlist a match means "continue"; on a denylist a match means
        // "refuse". The list itself is the same, only the polarity flips.
        if ($this->allow === $matches) {
            return null;
        }

        return Response::json(['error' => 'Forbidden'], 403);
    }

    /**
     * @param list<string> $cidrs
     *
     * @return list<array{bytes: string, prefix: int}>
     */
    private static function parseAll(array $cidrs): array
    {
        $networks = [];
        foreach ($cidrs as $cidr) {
            $networks[] = self::parse($cidr);
        }

        return $networks;
    }

    /**
     * @return array{bytes: string, prefix: int}
     */
    private static function parse(string $cidr): array
    {
        $slash = strpos($cidr, '/');
        $ip = $slash === false ? $cidr : substr($cidr, 0, $slash);
        $prefix = $slash === false ? null : substr($cidr, $slash + 1);

        $bytes = inet_pton($ip);
        if ($bytes === false) {
            throw new InvalidArgumentException("Not an IP address or CIDR network: '{$cidr}'.");
        }

        $maxPrefix = strlen($bytes) * 8;
        if ($prefix === null) {
            return ['bytes' => $bytes, 'prefix' => $maxPrefix];
        }

        if ($prefix === '' || !ctype_digit($prefix)) {
            throw new InvalidArgumentException("Not a valid CIDR prefix in '{$cidr}'.");
        }

        $length = (int) $prefix;
        if ($length > $maxPrefix) {
            throw new InvalidArgumentException("CIDR prefix out of range in '{$cidr}': 0..{$maxPrefix}.");
        }

        return ['bytes' => $bytes, 'prefix' => $length];
    }

    /**
     * @param list<array{bytes: string, prefix: int}> $networks
     */
    private static function matchesAny(string $ip, array $networks): bool
    {
        $bytes = inet_pton($ip);
        if ($bytes === false) {
            return false;
        }

        foreach ($networks as $network) {
            if (self::inNetwork($bytes, $network['bytes'], $network['prefix'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare the leading $prefix bits of two packed addresses.
     */
    private static function inNetwork(string $ip, string $network, int $prefix): bool
    {
        // A packed IPv4 address is 4 bytes and a packed IPv6 one is 16, so a
        // length mismatch is a family mismatch and can never match.
        if (strlen($ip) !== strlen($network)) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($ip, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ip[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
