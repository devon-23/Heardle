<?php

declare(strict_types=1);

namespace HeardLive\Import;

use RuntimeException;

/** The file isn't a Concert Archives export we can read (as opposed to one bad row). */
final class CsvFormatError extends RuntimeException
{
    /** @param list<string> $missingColumns */
    public function __construct(string $message, public readonly array $missingColumns = [])
    {
        parent::__construct($message);
    }
}
