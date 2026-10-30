<?php

declare(strict_types=1);

namespace HeardLive\Http\Controllers;

use HeardLive\Concerts\EventRepository;
use HeardLive\Http\Controller;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Http\View;
use InvalidArgumentException;

/** The concert review screen: confirm which performers you actually saw. */
final class ConcertController extends Controller
{
    public function __construct(View $view, private EventRepository $events)
    {
        parent::__construct($view);
    }

    public function index(Request $request): Response
    {
        if (!$this->events->hasEvents()) {
            return Response::redirect('/import');
        }

        $filter = $request->queryString('filter', 'all');
        if (!in_array($filter, EventRepository::FILTERS, true)) {
            $filter = 'all';
        }

        $byYear = [];
        foreach ($this->events->forReview($filter) as $event) {
            $byYear[substr($event['start_date'], 0, 4)][] = $event;
        }

        return $this->page('concerts/index', 'Your concerts', [
            'filter' => $filter,
            'stats' => $this->events->stats(),
            'counts' => $this->events->filterCounts(),
            'byYear' => $byYear,
            'imported' => isset($request->query['imported']) ? [
                'created' => (int) $request->queryString('imported'),
                'updated' => (int) $request->queryString('updated'),
                'removed' => (int) $request->queryString('removed'),
            ] : null,
        ]);
    }

    /** @param array<string, string> $params */
    public function attendance(Request $request, array $params): Response
    {
        $eventId = (int) $params['id'];
        if ($this->events->find($eventId) === null) {
            return $this->notFound($request);
        }

        $ids = $request->body['attended'] ?? [];
        $ids = is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : [];

        try {
            $this->events->setAttendance($eventId, $ids);
        } catch (InvalidArgumentException $e) {
            return $request->wantsJson()
                ? Response::json(['error' => $e->getMessage()], 422)
                : Response::redirect($this->back($request, $eventId));
        }

        return $request->wantsJson()
            ? Response::json($this->eventJson($eventId))
            : Response::redirect($this->back($request, $eventId));
    }

    /** @param array<string, string> $params */
    public function review(Request $request, array $params): Response
    {
        $eventId = (int) $params['id'];
        if ($this->events->find($eventId) === null) {
            return $this->notFound($request);
        }
        $this->events->markReviewed($eventId);

        return $request->wantsJson()
            ? Response::json($this->eventJson($eventId))
            : Response::redirect($this->back($request, $eventId));
    }

    public function reviewAll(Request $request): Response
    {
        $filter = (string) ($request->body['filter'] ?? '');
        if (!in_array($filter, EventRepository::FILTERS, true)) {
            return Response::redirect('/concerts');
        }
        $this->events->markFilterReviewed($filter);

        return Response::redirect('/concerts?filter=' . $filter);
    }

    /** @return array<string, mixed> */
    private function eventJson(int $eventId): array
    {
        $event = $this->events->find($eventId);

        return [
            'id' => $eventId,
            'needs_attention' => $event['needs_attention'],
            'flags_pending' => $event['flags_pending'],
            'attended' => count(array_filter($event['performances'], fn ($p) => $p['attended'])),
            'listed' => count($event['performances']),
            'performances' => array_map(fn ($p) => [
                'id' => $p['id'],
                'attended' => $p['attended'],
                'source' => $p['attended_source'],
            ], $event['performances']),
            'stats' => $this->events->stats(),
        ];
    }

    private function back(Request $request, int $eventId): string
    {
        $filter = (string) ($request->body['filter'] ?? 'all');
        $filter = in_array($filter, EventRepository::FILTERS, true) ? $filter : 'all';

        return "/concerts?filter=$filter#event-$eventId";
    }

    private function notFound(Request $request): Response
    {
        return $request->wantsJson()
            ? Response::json(['error' => 'Event not found'], 404)
            : $this->page('errors/message', 'Not found', ['message' => 'That concert doesn’t exist.'], 404);
    }
}
