<?php

declare(strict_types=1);

namespace MiGears\WebGuards\UserAgent;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A User-Agent before() hook.
 *
 * Two named constructors, the same shape as IpFilterGuard: blocking() refuses
 * clients whose User-Agent contains any of the patterns, allowing() refuses
 * clients whose User-Agent contains none of them. Matching is a case-insensitive
 * substring test, which is enough for the usual job — turning away known
 * scrapers and CLI clients — without pretending to parse a header that anything
 * can write.
 *
 * A User-Agent is trivially forged, so this is a speed bump, not a security
 * boundary; anything that must actually be protected needs authentication.
 * A missing or empty User-Agent counts as "no match": blocking() lets it
 * through, allowing() refuses it.
 */
final class UserAgentGuard
{
    /** @var list<string> lower-cased needles */
    private readonly array $needles;

    /**
     * @param list<string> $needles lower-cased patterns to look for
     * @param bool $allow true = allowlist, false = denylist
     */
    private function __construct(array $needles, private readonly bool $allow)
    {
        $this->needles = $needles;
    }

    /**
     * Refuse clients whose User-Agent contains any of $needles.
     *
     * @throws InvalidArgumentException when a pattern is empty
     */
    public static function blocking(string ...$needles): self
    {
        return new self(self::normalize($needles), false);
    }

    /**
     * Refuse clients whose User-Agent contains none of $needles.
     *
     * @throws InvalidArgumentException when a pattern is empty
     */
    public static function allowing(string ...$needles): self
    {
        return new self(self::normalize($needles), true);
    }

    public function __invoke(Request $request): ?Response
    {
        $agent = $request->header('user-agent');
        $agent = is_string($agent) ? strtolower($agent) : '';

        $matches = false;
        foreach ($this->needles as $needle) {
            if (str_contains($agent, $needle)) {
                $matches = true;
                break;
            }
        }

        // On an allowlist a match means "continue"; on a denylist a match means
        // "refuse". The needles are the same, only the polarity flips.
        if ($this->allow === $matches) {
            return null;
        }

        return Response::json(['error' => 'Forbidden'], 403);
    }

    /**
     * @param list<string> $needles
     *
     * @return list<string>
     */
    private static function normalize(array $needles): array
    {
        $normalized = [];
        foreach ($needles as $needle) {
            if (trim($needle) === '') {
                throw new InvalidArgumentException('A User-Agent pattern must not be empty.');
            }
            $normalized[] = strtolower($needle);
        }

        return $normalized;
    }
}
