<?php

declare(strict_types=1);

namespace HeardLive\Http\Controllers;

use HeardLive\Concerts\EventRepository;
use HeardLive\Http\Controller;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Http\View;
use HeardLive\Jobs\JobQueue;
use HeardLive\Jobs\ScanJob;
use HeardLive\Setlists\ScanRepository;
use InvalidArgumentException;

/** Starting and watching the setlist scan, and recording the choices it asks for. */
final class ScanController extends Controller
{
    // The worker checks in every second; this long without a check-in means it isn't running.
    private const WORKER_SILENCE_LIMIT = 10;

    public function __construct(
        View $view,
        private JobQueue $jobs,
        private ScanRepository $scans,
        private EventRepository $events,
        private string $mode,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): Response
    {
        if (!$this->events->hasEvents()) {
            return Response::redirect('/import');
        }

        return $this->page('scan/index', 'Setlist scan', [
            'job' => $this->jobs->latest(ScanJob::TYPE),
            'coverage' => $this->scans->coverage(),
            'mode' => $this->mode,
            'workerDown' => $this->workerDown(),
        ]);
    }

    public function start(Request $request): Response
    {
        $this->jobs->enqueue(ScanJob::TYPE, ['mode' => $this->mode]);

        return Response::redirect('/scan');
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $this->jobs->cancel((int) $params['id']);

        return Response::redirect('/scan');
    }

    /** @param array<string, string> $params */
    public function status(Request $request, array $params): Response
    {
        $job = $this->jobs->find((int) $params['id']);
        if ($job === null) {
            return Response::json(['error' => 'Job not found'], 404);
        }

        return Response::json([
            'id' => $job['id'],
            'status' => $job['status'],
            'stage' => $job['stage'],
            'done' => $job['done'],
            'total' => $job['total'],
            'label' => $job['current_label'],
            'error' => $job['error'],
            'worker_down' => $this->workerDown(),
        ]);
    }

    /** @param array<string, string> $params */
    public function chooseSetlist(Request $request, array $params): Response
    {
        $choice = (string) ($request->body['setlist'] ?? '');
        if ($choice === '') {
            return Response::redirect($this->back($request));
        }
        try {
            $this->scans->chooseSetlist((int) $params['id'], $choice === 'none' ? null : $choice);
        } catch (InvalidArgumentException) {
            // Stale form (e.g. a rescan replaced the candidates): just show the current state.
        }
        if ($choice !== 'none') {
            // Pull the chosen setlist's songs into the library.
            $this->jobs->enqueue(ScanJob::TYPE, ['mode' => $this->mode]);
        }

        return Response::redirect($this->back($request));
    }

    /** @param array<string, string> $params */
    public function chooseArtist(Request $request, array $params): Response
    {
        $choice = (string) ($request->body['mbid'] ?? '');
        if ($choice === '') {
            return Response::redirect($this->back($request));
        }
        try {
            $this->scans->chooseArtist((int) $params['id'], $choice === 'none' ? null : $choice);
        } catch (InvalidArgumentException) {
        }
        if ($choice !== 'none') {
            // The chosen artist's setlists still need finding.
            $this->jobs->enqueue(ScanJob::TYPE, ['mode' => $this->mode]);
        }

        return Response::redirect($this->back($request));
    }

    private function workerDown(): bool
    {
        $silent = $this->jobs->workerSilentFor();

        return $silent === null || $silent > self::WORKER_SILENCE_LIMIT;
    }

    private function back(Request $request): string
    {
        $filter = (string) ($request->body['filter'] ?? 'all');
        $filter = in_array($filter, EventRepository::FILTERS, true) ? $filter : 'all';
        $event = (int) ($request->body['event'] ?? 0);

        return "/concerts?filter=$filter" . ($event > 0 ? "#event-$event" : '');
    }
}
