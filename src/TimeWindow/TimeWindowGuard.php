<?php

declare(strict_types=1);

namespace MiGears\WebGuards\TimeWindow;

use Closure;
use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A time-window before() hook.
 *
 * A request is allowed only while the local clock is inside the window, and
 * refused with a 403 outside it — a back office that answers only during
 * business hours, an endpoint that is open only overnight.
 *
 * Times are 'HH:MM' in the process time zone. The bounds are inclusive to the
 * minute, and a start later than the end means the window wraps past midnight
 * ('22:00' to '06:00' is the night shift, not an error).
 *
 * Time comes from an injected clock, so a test decides what "now" is.
 */
final class TimeWindowGuard
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string $start window opens, 'HH:MM'
     * @param string $end window closes, 'HH:MM'
     * @param null|callable(): int $clock the current unix time; defaults to time()
     *
     * @throws InvalidArgumentException when a time is not in HH:MM 24-hour form
     */
    public function __construct(
        private readonly string $start,
        private readonly string $end,
        ?callable $clock = null,
    ) {
        self::assertTime($start, 'start');
        self::assertTime($end, 'end');

        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function __invoke(Request $request): ?Response
    {
        $now = date('H:i', ($this->clock)());

        // A start after the end wraps past midnight, so membership is a union
        // of the two tails rather than one interval.
        $within = $this->start <= $this->end
            ? $now >= $this->start && $now <= $this->end
            : $now >= $this->start || $now <= $this->end;

        if ($within) {
            return null;
        }

        return Response::json(['error' => 'Forbidden'], 403);
    }

    private static function assertTime(string $value, string $name): void
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new InvalidArgumentException("The {$name} time must be in HH:MM 24-hour form, got '{$value}'.");
        }
    }
}
