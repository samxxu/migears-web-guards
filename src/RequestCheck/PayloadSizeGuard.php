<?php

declare(strict_types=1);

namespace MiGears\WebGuards\RequestCheck;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A request-size before() hook.
 *
 * A request whose declared Content-Length exceeds the limit is refused with a
 * 413 before its body is read, so an oversized upload is turned away instead of
 * being parsed into memory first.
 *
 * The size comes from Content-Length and nowhere else: it is the only figure
 * available before the body is consumed. A request without a usable
 * Content-Length — chunked transfer, or a header that is not a number — is let
 * through, because there is nothing to judge it by; a web server's own
 * client_max_body_size is the backstop for those.
 */
final class PayloadSizeGuard
{
    /**
     * @param int $maxBytes the largest allowed body, in bytes; >= 1
     *
     * @throws InvalidArgumentException when the limit is below 1 byte
     */
    public function __construct(private readonly int $maxBytes)
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The maximum size must be at least 1 byte.');
        }
    }

    public function __invoke(Request $request): ?Response
    {
        $length = $request->header('content-length');
        if (!is_string($length) || !ctype_digit($length)) {
            return null;
        }

        if ((int) $length <= $this->maxBytes) {
            return null;
        }

        return Response::json(['error' => 'Payload Too Large'], 413);
    }
}
