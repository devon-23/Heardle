<?php

declare(strict_types=1);

namespace HeardLive\Export;

/** Builds CSV files: UTF-8 with a byte-order mark (so Excel reads accents right), CRLF line ends. */
final class Csv
{
    private const BOM = "\u{FEFF}";

    /**
     * @param list<string> $header
     * @param iterable<list<string|int|null>> $rows
     * @param bool $spreadsheetSafe prefix cells that start like a formula (=, +, -, @) with a quote,
     *   for files meant to be opened in a spreadsheet. Off for transfer files, whose titles must stay exact.
     */
    public static function build(array $header, iterable $rows, bool $spreadsheetSafe = false): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            $cells = array_map(fn ($v) => (string) $v, $row);
            if ($spreadsheetSafe) {
                $cells = array_map(fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $cells);
            }
            fputcsv($out, $cells, ',', '"', '', "\r\n");
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return self::BOM . $csv;
    }

    /** A filename-safe version of a playlist name: "Heard Live (1 of 7)" → "Heard Live (1 of 7).csv". */
    public static function filename(string $name): string
    {
        $safe = trim((string) preg_replace(['/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '/\s+/u'], ['', ' '], $name));

        return ($safe === '' ? 'Heard Live' : mb_substr($safe, 0, 100)) . '.csv';
    }
}
