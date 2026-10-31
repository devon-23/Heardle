<?php

declare(strict_types=1);

namespace HeardLive\Http\Controllers;

use HeardLive\Http\Controller;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Http\View;
use HeardLive\Stats\StatsRepository;

final class StatsController extends Controller
{
    public function __construct(View $view, private StatsRepository $stats)
    {
        parent::__construct($view);
    }

    public function index(Request $request): Response
    {
        return $this->page('stats/index', 'Stats', [
            'summary' => $this->stats->summary(),
            'mostHeard' => $this->stats->mostHeard(10),
            'topArtists' => $this->stats->topArtists(10),
            'openers' => $this->stats->openers(8),
            'covers' => $this->stats->covers(12),
            'nights' => $this->stats->biggestNights(5),
            'caughtOnce' => $this->stats->caughtOnce(3, 12),
            'coverage' => $this->stats->coverage(12),
        ]);
    }
}
