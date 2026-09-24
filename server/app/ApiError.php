<?php
declare(strict_types=1);

namespace Arche;

/**
 * An error the API reports to the client as JSON: `{"error": "<code>"}` with
 * the HTTP status. Codes are stable identifiers the PWA translates; they never
 * carry internal detail (moderation signals in particular stay server-side).
 */
final class ApiError extends \RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        public readonly array $extra = [],
    ) {
        parent::__construct($error, $status);
    }
}
