<?php

declare(strict_types=1);

namespace MiGears\WebGuards\RequestCheck;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\Web\Response;

/**
 * A request Content-Type before() hook.
 *
 * A request that carries a body — POST, PUT, PATCH by default — must declare a
 * Content-Type the caller allows, or it is refused with a 415 before a handler
 * tries to read a body it cannot parse. Methods that carry no body pass through.
 *
 * The comparison is on the media type alone, so 'application/json; charset=utf-8'
 * matches 'application/json'. A trailing wildcard ('application/*') matches the
 * whole family when that is the caller's intent.
 */
final class ContentTypeGuard
{
    /** @var list<string> lower-cased media types */
    private readonly array $allowedTypes;

    /** @var list<string> upper-cased methods */
    private readonly array $methods;

    /**
     * @param list<string> $allowedTypes media types to accept, e.g. ['application/json']
     * @param list<string> $methods methods expected to carry a body
     *
     * @throws InvalidArgumentException when no type is allowed or a value is empty
     */
    public function __construct(array $allowedTypes, array $methods = ['POST', 'PUT', 'PATCH'])
    {
        if ($allowedTypes === []) {
            throw new InvalidArgumentException('At least one allowed content type is required.');
        }

        $this->allowedTypes = self::normalize($allowedTypes, 'content type', false);
        $this->methods = self::normalize($methods, 'method', true);
    }

    public function __invoke(Request $request): ?Response
    {
        if (!in_array(strtoupper($request->method), $this->methods, true)) {
            return null;
        }

        $header = $request->header('content-type');
        $mediaType = is_string($header) ? strtolower(trim(explode(';', $header)[0])) : '';

        if ($this->allows($mediaType)) {
            return null;
        }

        return Response::json(['error' => 'Unsupported Media Type'], 415);
    }

    private function allows(string $mediaType): bool
    {
        if ($mediaType === '') {
            return false;
        }

        foreach ($this->allowedTypes as $allowed) {
            if ($allowed === $mediaType) {
                return true;
            }
            if (str_ends_with($allowed, '/*') && str_starts_with($mediaType, substr($allowed, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function normalize(array $values, string $label, bool $upper): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                throw new InvalidArgumentException("A {$label} must not be empty.");
            }
            $normalized[] = $upper ? strtoupper($trimmed) : strtolower($trimmed);
        }

        return $normalized;
    }
}
