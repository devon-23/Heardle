<?php

declare(strict_types=1);

namespace HeardLive\Http\Controllers;

use Closure;
use HeardLive\Http\Controller;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Http\View;
use HeardLive\Jobs\JobQueue;
use HeardLive\Jobs\ScanJob;
use HeardLive\Matching\ItunesException;
use HeardLive\Matching\MatchRepository;
use HeardLive\Matching\RecordingMatcher;
use HeardLive\Songs\SongRepository;
use InvalidArgumentException;

/** Reviewing which Apple Music recording each song uses. Every decision is saved as it's made. */
final class MatchController extends Controller
{
    /** @param Closure(): RecordingMatcher $matcher built only when a search is run */
    public function __construct(
        View $view,
        private MatchRepository $matches,
        private SongRepository $songs,
        private Closure $matcher,
        private JobQueue $jobs,
        private string $mode,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): Response
    {
        $counts = $this->matches->counts();
        $tab = $request->queryString('tab');
        if (!in_array($tab, MatchRepository::TABS, true)) {
            // Start where there's work to do.
            $tab = 'review';
            foreach (['review', 'unmatched', 'covers', 'auto'] as $candidate) {
                if ($counts[$candidate] > 0) {
                    $tab = $candidate;
                    break;
                }
            }
        }
        $q = trim($request->queryString('q'));

        return $this->page('matches/index', 'Apple Music', [
            'tab' => $tab,
            'q' => $q,
            'counts' => $counts,
            'result' => $this->matches->tab($tab, (int) $request->queryString('page', '1'), $q),
            'mode' => $this->mode,
            'scanning' => in_array($this->jobs->latest(ScanJob::TYPE)['status'] ?? null, JobQueue::ACTIVE, true),
            'error' => $request->queryString('error'),
        ]);
    }

    /** @param array<string, string> $params */
    public function choose(Request $request, array $params): Response
    {
        return $this->decide($request, (int) $params['id'], 'Picked', fn (int $id) => $this->matches->choose($id, (int) ($request->body['recording'] ?? 0)));
    }

    /** @param array<string, string> $params */
    public function accept(Request $request, array $params): Response
    {
        return $this->decide($request, (int) $params['id'], 'Confirmed', fn (int $id) => $this->matches->accept($id));
    }

    /** @param array<string, string> $params */
    public function reopen(Request $request, array $params): Response
    {
        return $this->decide($request, (int) $params['id'], 'Moved to review', fn (int $id) => $this->matches->reopen($id));
    }

    /** @param array<string, string> $params */
    public function exclude(Request $request, array $params): Response
    {
        $excluded = ($request->body['excluded'] ?? '1') === '1';

        return $this->decide($request, (int) $params['id'], $excluded ? 'Left off the playlist' : 'Back on the playlist',
            fn (int $id) => $this->songs->setExcluded($id, $excluded));
    }

    public function approveAll(Request $request): Response
    {
        $this->matches->approveAll();

        return Response::redirect('/matches?tab=chosen');
    }

    /**
     * Searches Apple Music again for one song, with the user's own terms (or the automatic ones if blank).
     *
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $terms = trim((string) ($request->body['terms'] ?? ''));
        $song = $this->matches->songForSearch($id);
        if ($song === null) {
            return Response::redirect('/matches');
        }

        try {
            $result = ($this->matcher)()->match(['search_terms' => $terms] + $song);
        } catch (ItunesException) {
            return Response::redirect($this->back($request, $id, 'error=search'));
        }
        $this->matches->save($id, $result, $terms);

        if (($request->body['from'] ?? '') === 'song') {
            return Response::redirect("/songs/$id#apple-music");
        }
        // Show the song again wherever it landed, at the top of that list.
        $tab = $song['original_artist_name'] !== null && $result['status'] === 'unmatched' ? 'covers' : $result['status'];

        return Response::redirect('/matches?' . http_build_query(['tab' => $tab, 'q' => $song['title_display']]) . "#song-$id");
    }

    /** @param Closure(int): void $action */
    private function decide(Request $request, int $id, string $message, Closure $action): Response
    {
        try {
            $action($id);
        } catch (InvalidArgumentException $e) {
            return $request->wantsJson()
                ? Response::json(['error' => $e->getMessage()], 422)
                : Response::redirect($this->back($request, $id));
        }

        return $request->wantsJson()
            ? Response::json(['ok' => true, 'message' => $message])
            : Response::redirect($this->back($request, $id));
    }

    /** Where a form goes after saving: the song page it came from, or the same review list. */
    private function back(Request $request, int $id, string $extra = ''): string
    {
        if (($request->body['from'] ?? '') === 'song') {
            return "/songs/$id" . ($extra !== '' ? "?$extra" : '') . '#apple-music';
        }
        $tab = (string) ($request->body['tab'] ?? '');
        $query = array_filter([
            'tab' => in_array($tab, MatchRepository::TABS, true) ? $tab : null,
            'q' => trim((string) ($request->body['q'] ?? '')) ?: null,
            'page' => max(1, (int) ($request->body['page'] ?? 1)) > 1 ? (int) $request->body['page'] : null,
        ]);

        return '/matches?' . http_build_query($query) . ($extra !== '' ? "&$extra" : '') . "#song-$id";
    }
}
