<?php

declare(strict_types=1);

namespace HeardLive\Support;

use InvalidArgumentException;

/** Minimal .env parser: KEY=value lines, # comments, optional quotes. */
final class Env
{
    /** @return array<string, string> */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        return self::parse((string) file_get_contents($path));
    }

    /** @return array<string, string> */
    public static function parse(string $contents): array
    {
        $values = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                throw new InvalidArgumentException(sprintf('.env line %d has no "="', $number + 1));
            }

            $key = trim(substr($line, 0, $eq));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException(sprintf('.env line %d has an invalid key "%s"', $number + 1, $key));
            }

            $values[$key] = self::parseValue(trim(substr($line, $eq + 1)));
        }

        return $values;
    }

    private static function parseValue(string $raw): string
    {
        $quote = $raw[0] ?? '';
        if (($quote === '"' || $quote === "'") && strlen($raw) >= 2 && str_ends_with($raw, $quote)) {
            return substr($raw, 1, -1);
        }

        // Unquoted values may carry a trailing " # comment".
        $hash = strpos($raw, ' #');
        return $hash === false ? $raw : rtrim(substr($raw, 0, $hash));
    }
}
