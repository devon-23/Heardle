<?php

declare(strict_types=1);

namespace HeardLive\Matching;

use RuntimeException;

/**
 * kind: 'throttled' (Apple kept refusing requests — stop and try later),
 * 'temporary' (one search failed — skip the song; the next scan retries it).
 */
final class ItunesException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public function stopsTheScan(): bool
    {
        return $this->kind === 'throttled';
    }
}
