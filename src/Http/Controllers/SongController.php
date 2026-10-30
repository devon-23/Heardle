<?php

declare(strict_types=1);

namespace HeardLive\Http\Controllers;

use HeardLive\Http\Controller;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Http\View;
use HeardLive\Songs\SongRepository;
use InvalidArgumentException;

/** The song library: every unique song you've heard live, and where. */
final class SongController extends Controller
{
    public function __construct(View $view, private SongRepository $songs)
    {
        parent::__construct($view);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim($request->queryString('q')),
            'artist' => (int) $request->queryString('artist'),
            'show' => in_array($request->queryString('show'), SongRepository::SHOWS, true) ? $request->queryString('show') : 'all',
            'sort' => in_array($request->queryString('sort'), SongRepository::SORTS, true) ? $request->queryString('sort') : 'heard',
            'page' => max(1, (int) $request->queryString('page', '1')),
        ];

        return $this->page('songs/index', 'Songs', [
            'filters' => $filters,
            'stats' => $this->songs->stats(),
            'mostHeard' => $this->songs->mostHeard(5),
            'performers' => $this->songs->performers(),
            'result' => $this->songs->search($filters),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $song = $this->songs->find((int) $params['id']);
        if ($song === null) {
            return $this->page('errors/message', 'Not found', ['message' => 'That song isn’t in your library.'], 404);
        }

        return $this->page('songs/show', $song['title_display'], [
            'song' => $song,
            'mergeTargets' => $this->songs->mergeTargets($song),
            'merged' => isset($request->query['merged']),
        ]);
    }

    /** @param array<string, string> $params */
    public function exclude(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $this->songs->setExcluded($id, ($request->body['excluded'] ?? '1') === '1');

        return Response::redirect("/songs/$id");
    }

    /** @param array<string, string> $params */
    public function merge(Request $request, array $params): Response
    {
        $from = (int) $params['id'];
        $into = (int) ($request->body['into'] ?? 0);
        try {
            $this->songs->merge($from, $into);
        } catch (InvalidArgumentException) {
            return Response::redirect("/songs/$from");
        }

        return Response::redirect("/songs/$into?merged=1");
    }
}
