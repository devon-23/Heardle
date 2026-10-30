<?php

declare(strict_types=1);

namespace HeardLive\Setlists;

use RuntimeException;

/**
 * kind: 'auth' (bad or missing key — stop), 'budget' (daily request budget used — pause),
 * 'temporary' (rate limits, server errors or network trouble that outlasted the retries).
 */
final class SetlistFmException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public function stopsTheScan(): bool
    {
        return $this->kind === 'auth' || $this->kind === 'budget';
    }
}
