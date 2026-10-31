<?php

declare(strict_types=1);

namespace HeardLive\Http\Controllers;

use HeardLive\Export\Csv;
use HeardLive\Export\ExportRepository;
use HeardLive\Http\Controller;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Http\View;
use HeardLive\Matching\MatchRepository;

/** Downloading the playlist as CSV for a transfer service, in parts if the service caps a playlist's size. */
final class ExportController extends Controller
{
    /** Songs per file. Soundiiz's free plan takes 200 per playlist (checked 2026-10-30); 0 = one file. */
    public const SIZES = [200, 500, 0];
    public const MIN_HEARD = [1, 2, 3, 5];

    public function __construct(View $view, private ExportRepository $exports, private MatchRepository $matches)
    {
        parent::__construct($view);
    }

    public function index(Request $request): Response
    {
        $options = $this->options($request);
        $tracks = $this->exports->tracks($options['min'], $options['order']);
        $parts = [];
        $from = 1;
        foreach (ExportRepository::parts($tracks, $options['size']) as $i => $part) {
            $parts[] = ['part' => $i + 1, 'from' => $from, 'to' => $from + count($part) - 1];
            $from += count($part);
        }

        return $this->page('export/index', 'Export', [
            'options' => $options,
            'tracks' => count($tracks),
            'minutes' => (int) round(array_sum(array_column($tracks, 'duration_ms')) / 60000),
            'parts' => $parts,
            'counts' => $this->matches->counts(),
            'missing' => count($this->exports->missing()),
        ]);
    }

    public function playlist(Request $request): Response
    {
        $options = $this->options($request);
        $format = in_array($request->queryString('format'), ExportRepository::FORMATS, true) ? $request->queryString('format') : 'transfer';
        $parts = ExportRepository::parts($this->exports->tracks($options['min'], $options['order']), $options['size']);
        $index = (int) $request->queryString('part', '1') - 1;
        if (!isset($parts[$index])) {
            return $this->page('errors/message', 'Not found', ['message' => 'There’s no such part of the playlist. Go back to Export and download it again.'], 404);
        }

        $name = $options['name'] . (count($parts) > 1 ? ' (' . ($index + 1) . ' of ' . count($parts) . ')' : '');
        $csv = $format === 'transfer'
            ? Csv::build(ExportRepository::TRANSFER_HEADER, ExportRepository::rows($parts[$index], 'transfer'))
            : Csv::build(ExportRepository::DETAILS_HEADER, ExportRepository::rows($parts[$index], 'details'), spreadsheetSafe: true);

        return Response::download($csv, Csv::filename($format === 'details' ? "$name - details" : $name));
    }

    public function missing(Request $request): Response
    {
        $csv = Csv::build(ExportRepository::MISSING_HEADER, ExportRepository::missingRows($this->exports->missing()), spreadsheetSafe: true);

        return Response::download($csv, Csv::filename('Heard Live - not on the playlist'));
    }

    /** @return array{name: string, min: int, order: string, size: int} */
    private function options(Request $request): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $request->queryString('name')));
        $min = (int) $request->queryString('min', '1');
        $size = (int) $request->queryString('size', '200');
        $order = $request->queryString('order');

        return [
            'name' => $name === '' ? 'Heard Live' : mb_substr($name, 0, 80),
            'min' => in_array($min, self::MIN_HEARD, true) ? $min : 1,
            'order' => in_array($order, ExportRepository::ORDERS, true) ? $order : 'first',
            'size' => in_array($size, self::SIZES, true) ? $size : 200,
        ];
    }
}
