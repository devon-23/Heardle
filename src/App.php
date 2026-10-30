<?php

declare(strict_types=1);

namespace HeardLive;

use HeardLive\Concerts\EventRepository;
use HeardLive\Http\Controllers\ConcertController;
use HeardLive\Http\Controllers\ImportController;
use HeardLive\Http\Controllers\ScanController;
use HeardLive\Http\Controllers\SongController;
use HeardLive\Songs\SongRepository;
use HeardLive\Http\Csrf;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Http\Router;
use HeardLive\Http\View;
use HeardLive\Import\UploadStore;
use HeardLive\Support\Config;
use HeardLive\Support\Db;
use PDO;
use Throwable;

final class App
{
    private const SECURITY_HEADERS = [
        'Content-Security-Policy' => "default-src 'self'; style-src 'self' https://fonts.googleapis.com; "
            . "font-src https://fonts.gstatic.com; img-src 'self' data: https://*.mzstatic.com; "
            . "script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
    ];

    private Router $router;
    private View $view;

    public function __construct(
        private Config $config,
        private ?PDO $pdo = null,
        private ?UploadStore $uploads = null,
    ) {
        $this->view = new View($config->rootPath . '/views');
        $this->router = new Router();
        $this->routes();
    }

    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->match($request->method, $request->path);
            if ($match['status'] === 200 && $request->method === 'POST' && !Csrf::verify($request)) {
                $response = $request->wantsJson()
                    ? Response::json(['error' => 'Your session expired. Reload the page.'], 403)
                    : $this->page('errors/message', 'Session expired', 403, ['message' => 'Your session expired. Go back, reload the page and try again.']);
            } else {
                $response = match ($match['status']) {
                    200 => ($match['handler'])($request, $match['params']),
                    405 => $this->page('errors/message', 'Not allowed', 405, ['message' => 'That action isn’t supported here.']),
                    default => $this->page('errors/message', 'Not found', 404, ['message' => 'There’s nothing at this address.']),
                };
            }
            if ($match['status'] === 405) {
                $response->headers['Allow'] = implode(', ', $match['allowed']);
            }
        } catch (Throwable $e) {
            error_log((string) $e);
            $detail = $this->config->isLocal() ? $e->getMessage() : 'Something went wrong.';
            $response = $this->page('errors/message', 'Error', 500, ['message' => $detail]);
        }

        $response->headers += self::SECURITY_HEADERS;

        return $response;
    }

    private function routes(): void
    {
        $this->router->get('/', fn () => $this->page('landing', 'Every song you’ve heard live', 200, [
            'hasEvents' => (new EventRepository($this->db()))->hasEvents(),
        ]));

        $this->router->get('/import', fn (Request $r) => $this->imports()->form($r));
        $this->router->post('/import', fn (Request $r) => $this->imports()->upload($r));
        $this->router->get('/import/preview', fn (Request $r) => $this->imports()->preview($r));
        $this->router->post('/import/confirm', fn (Request $r) => $this->imports()->confirm($r));

        $this->router->get('/concerts', fn (Request $r) => $this->concerts()->index($r));
        $this->router->post('/concerts/review-all', fn (Request $r) => $this->concerts()->reviewAll($r));
        $this->router->post('/events/{id}/attendance', fn (Request $r, array $p) => $this->concerts()->attendance($r, $p));
        $this->router->post('/events/{id}/review', fn (Request $r, array $p) => $this->concerts()->review($r, $p));

        $this->router->get('/scan', fn (Request $r) => $this->scans()->index($r));
        $this->router->post('/scan', fn (Request $r) => $this->scans()->start($r));
        $this->router->post('/scan/{id}/cancel', fn (Request $r, array $p) => $this->scans()->cancel($r, $p));
        $this->router->get('/api/jobs/{id}', fn (Request $r, array $p) => $this->scans()->status($r, $p));
        $this->router->post('/performances/{id}/setlist', fn (Request $r, array $p) => $this->scans()->chooseSetlist($r, $p));
        $this->router->post('/artists/{id}/choose', fn (Request $r, array $p) => $this->scans()->chooseArtist($r, $p));

        $this->router->get('/songs', fn (Request $r) => $this->songs()->index($r));
        $this->router->get('/songs/{id}', fn (Request $r, array $p) => $this->songs()->show($r, $p));
        $this->router->post('/songs/{id}/exclude', fn (Request $r, array $p) => $this->songs()->exclude($r, $p));
        $this->router->post('/songs/{id}/merge', fn (Request $r, array $p) => $this->songs()->merge($r, $p));
    }

    private function songs(): SongController
    {
        return new SongController($this->view, new SongRepository($this->db()));
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect($this->config->dbPath);
    }

    private function scans(): ScanController
    {
        $services = new Services($this->config, $this->db());

        return new ScanController(
            $this->view,
            $services->jobs(),
            $services->scanRepository(),
            new EventRepository($this->db()),
            $this->config->setlistFmMode,
        );
    }

    private function imports(): ImportController
    {
        $this->uploads ??= new UploadStore($this->config->rootPath . '/storage/uploads');

        return new ImportController($this->view, $this->db(), $this->uploads);
    }

    private function concerts(): ConcertController
    {
        return new ConcertController($this->view, new EventRepository($this->db()));
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, string $title, int $status = 200, array $data = []): Response
    {
        return Response::html($this->view->render($view, ['title' => $title] + $data), $status);
    }
}
