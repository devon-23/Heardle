<?php

declare(strict_types=1);

namespace Tests\Integration;

use HeardLive\App;
use HeardLive\Http\Csrf;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Import\UploadStore;
use HeardLive\Support\Config;
use PDO;
use Tests\TestCase;

final class AppTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private string $uploadDir;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = $this->migratedDb();
        $this->uploadDir = $this->tempDir();
        $this->app = new App(
            Config::fromArray(['DB_PATH' => ':memory:'], dirname(__DIR__, 2)),
            $this->pdo,
            new UploadStore($this->uploadDir, requireHttpUpload: false),
        );
    }

    private function get(string $path, array $query = []): Response
    {
        return $this->app->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $body = [], array $files = [], bool $json = false): Response
    {
        $headers = $json ? ['accept' => 'application/json'] : [];

        return $this->app->handle(new Request('POST', $path, [], $body + ['_token' => Csrf::token()], $headers, $files));
    }

    private function uploadSample(): string
    {
        $fixture = dirname(__DIR__) . '/fixtures/concert-archives/sample.csv';
        $response = $this->post('/import', files: ['csv' => [
            'name' => 'export.csv', 'tmp_name' => $fixture, 'size' => filesize($fixture), 'error' => UPLOAD_ERR_OK,
        ]]);
        $this->assertSame(303, $response->status);
        parse_str((string) parse_url($response->headers['Location'], PHP_URL_QUERY), $query);

        return $query['file'];
    }

    public function testLandingPageRenders(): void
    {
        $response = $this->get('/');

        $this->assertSame(200, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
        $this->assertContains('Every song you’ve heard live.', $response->body);
        $this->assertContains('Import your concerts', $response->body);
    }

    public function testSecurityHeadersAreSet(): void
    {
        $response = $this->get('/');

        $this->assertContains("script-src 'self'", $response->headers['Content-Security-Policy']);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    public function testUnknownPathRenders404Page(): void
    {
        $response = $this->get('/does-not-exist');

        $this->assertSame(404, $response->status);
        $this->assertContains('Not found', $response->body);
        $this->assertTrue(isset($response->headers['Content-Security-Policy']));
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $response = $this->post('/');

        $this->assertSame(405, $response->status);
        $this->assertSame('GET', $response->headers['Allow']);
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $response = $this->app->handle(new Request('POST', '/import'));

        $this->assertSame(403, $response->status);
        $this->assertContains('Session expired', $response->body);
    }

    public function testConcertsRedirectsToImportWhenEmpty(): void
    {
        $response = $this->get('/concerts');

        $this->assertSame(303, $response->status);
        $this->assertSame('/import', $response->headers['Location']);
    }

    public function testUploadRejectsMissingAndNonCsvFiles(): void
    {
        $this->assertContains('Choose your Concert Archives CSV', $this->post('/import')->body);

        $response = $this->post('/import', files: ['csv' => [
            'name' => 'photo.png', 'tmp_name' => __FILE__, 'size' => 10, 'error' => UPLOAD_ERR_OK,
        ]]);
        $this->assertSame(422, $response->status);
        $this->assertContains('isn’t a .csv file', $response->body);
    }

    public function testWrongCsvShowsFormatErrorOnPreview(): void
    {
        $file = $this->tempDir() . '/other.csv';
        file_put_contents($file, "Name,Email\nA,b@c\n");
        $response = $this->post('/import', files: ['csv' => [
            'name' => 'other.csv', 'tmp_name' => $file, 'size' => filesize($file), 'error' => UPLOAD_ERR_OK,
        ]]);
        parse_str((string) parse_url($response->headers['Location'], PHP_URL_QUERY), $query);

        $preview = $this->get('/import/preview', ['file' => $query['file']]);
        $this->assertSame(422, $preview->status);
        $this->assertContains('Missing columns', $preview->body);
    }

    public function testFullImportFlowAndAttendanceToggle(): void
    {
        $token = $this->uploadSample();

        $preview = $this->get('/import/preview', ['file' => $token]);
        $this->assertSame(200, $preview->status);
        $this->assertContains('Import 4 concerts', $preview->body);
        $this->assertContains('Row 7', $preview->body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(), 'preview saves nothing');

        $confirm = $this->post('/import/confirm', ['file' => $token]);
        $this->assertSame(303, $confirm->status);
        $this->assertSame('/concerts?imported=4&updated=0&removed=0', $confirm->headers['Location']);
        $this->assertSame([], array_values(array_diff(scandir($this->uploadDir), ['.', '..'])), 'upload deleted');

        $page = $this->get('/concerts', ['imported' => '4']);
        $this->assertSame(200, $page->status);
        $this->assertContains('Imported 4 concerts.', $page->body);
        $this->assertContains('THE MIDNIGHT ARCADE', strtoupper($page->body));
        $this->assertContains('Riverbend Festival 2025', $page->body);

        $eventId = (int) $this->pdo->query("SELECT id FROM events WHERE ca_url LIKE '%harbor-pavilion'")->fetchColumn();
        $ids = $this->pdo->query("SELECT id FROM performances WHERE event_id = $eventId ORDER BY position")->fetchAll(PDO::FETCH_COLUMN);

        $response = $this->post("/events/$eventId/attendance", ['attended' => [(string) $ids[0]]], json: true);
        $this->assertSame(200, $response->status);
        $data = json_decode($response->body, true);
        $this->assertSame(1, $data['attended']);
        $this->assertSame(3, $data['listed']);
        $this->assertSame(['csv', 'user', 'csv'], array_column($data['performances'], 'source'));
        $this->assertSame(6, $data['stats']['attended']);
    }

    public function testAttendanceRejectsOtherEventsPerformances(): void
    {
        $this->post('/import/confirm', ['file' => $this->uploadSample()]);
        $foreign = (int) $this->pdo->query("SELECT p.id FROM performances p JOIN events e ON e.id = p.event_id WHERE e.ca_url LIKE '%the-cellar'")->fetchColumn();
        $eventId = (int) $this->pdo->query("SELECT id FROM events WHERE ca_url LIKE '%harbor-pavilion'")->fetchColumn();

        $response = $this->post("/events/$eventId/attendance", ['attended' => [(string) $foreign]], json: true);
        $this->assertSame(422, $response->status);
        $this->assertSame(404, $this->post('/events/9999/attendance', [], json: true)->status);
    }

    public function testScanScreenStartsAJobAndReportsStatus(): void
    {
        $this->post('/import/confirm', ['file' => $this->uploadSample()]);

        $page = $this->get('/scan');
        $this->assertSame(200, $page->status);
        $this->assertContains('Start scan', $page->body);
        $this->assertContains('Using saved test data', $page->body);
        $this->assertContains('worker isn’t running', $page->body);

        $this->assertSame(303, $this->post('/scan')->status);
        $id = (int) $this->pdo->query('SELECT id FROM jobs')->fetchColumn();
        $this->assertSame($id, (int) $this->pdo->query('SELECT MAX(id) FROM jobs')->fetchColumn(), 'one job');

        $status = json_decode($this->get("/api/jobs/$id")->body, true);
        $this->assertSame('queued', $status['status']);
        $this->assertSame(true, $status['worker_down']);
        $this->assertContains('Cancel', $this->get('/scan')->body);

        $this->post("/scan/$id/cancel");
        $this->assertSame('canceled', json_decode($this->get("/api/jobs/$id")->body, true)['status']);
    }

    public function testSetlistChoiceFormsAppearAndSave(): void
    {
        $this->post('/import/confirm', ['file' => $this->uploadSample()]);
        $services = new \HeardLive\Services(\HeardLive\Support\Config::fromArray([], dirname(__DIR__, 2)), $this->pdo);
        $jobId = $services->jobs()->enqueue('scan');
        $services->jobs()->claimNext();
        $services->scanJob()->run($jobId);

        $page = $this->get('/concerts', ['filter' => 'attention']);
        $this->assertContains('Setlist for Quiet Engines', $page->body);
        $this->assertContains('Setlist for Low Tide Choir', $page->body);
        $this->assertContains('not on setlist.fm', $this->get('/concerts', ['filter' => 'missing'])->body);

        $performance = (int) $this->pdo->query(
            "SELECT p.id FROM performances p JOIN artists a ON a.id = p.artist_id WHERE a.name = 'Quiet Engines'"
        )->fetchColumn();
        $response = $this->post("/performances/$performance/setlist", ['setlist' => '1a2b3c05', 'filter' => 'attention', 'event' => '3']);
        $this->assertSame('/concerts?filter=attention#event-3', $response->headers['Location']);
        $this->assertSame('matched', $this->pdo->query("SELECT setlist_status FROM performances WHERE id = $performance")->fetchColumn());
        $this->assertTrue(!str_contains($this->get('/concerts', ['filter' => 'attention'])->body, 'Setlist for Quiet Engines'));
    }

    public function testSongLibraryPagesAndActions(): void
    {
        $this->post('/import/confirm', ['file' => $this->uploadSample()]);
        $services = new \HeardLive\Services(\HeardLive\Support\Config::fromArray([], dirname(__DIR__, 2)), $this->pdo);
        $jobId = $services->jobs()->enqueue('scan');
        $services->jobs()->claimNext();
        $services->scanJob()->run($jobId);

        $index = $this->get('/songs');
        $this->assertSame(200, $index->status);
        $this->assertContains('Neon Hours', $index->body);
        $this->assertContains('cover of Blondie', $index->body);
        $this->assertContains('Heard the most', $index->body);
        $search = $this->get('/songs', ['q' => 'orb'])->body;
        $this->assertContains('Orbit', $search);
        $this->assertContains('>1 song<', $search);
        $this->assertSame(200, $this->get('/songs', ['show' => 'nonsense', 'sort' => 'nonsense'])->status, 'bad filters fall back');

        $id = (int) $this->pdo->query("SELECT id FROM songs WHERE title_display = 'Neon Hours'")->fetchColumn();
        $show = $this->get("/songs/$id");
        $this->assertContains('Where you heard it', $show->body);
        $this->assertContains('played ×2', $show->body);
        $this->assertContains('https://www.setlist.fm/setlist/fixture/1a2b3c01.html', $show->body);

        $this->assertSame("/songs/$id", $this->post("/songs/$id/exclude", ['excluded' => '1'])->headers['Location']);
        $this->assertTrue(!str_contains($this->get('/songs')->body, '>Neon Hours<'));

        $into = (int) $this->pdo->query("SELECT id FROM songs WHERE title_display = 'Night Drive'")->fetchColumn();
        $this->assertSame("/songs/$into?merged=1", $this->post("/songs/$id/merge", ['into' => (string) $into])->headers['Location']);
        $this->assertSame(404, $this->get("/songs/$id")->status, 'merged-away song is gone');
    }

    public function testMatchReviewPagesAndActions(): void
    {
        $this->post('/import/confirm', ['file' => $this->uploadSample()]);
        $services = new \HeardLive\Services(Config::fromArray([], dirname(__DIR__, 2)), $this->pdo);
        $jobId = $services->jobs()->enqueue('scan');
        $services->jobs()->claimNext();
        $services->scanJob()->run($jobId);
        $song = fn (string $title) => (int) $this->pdo->query("SELECT id FROM songs WHERE title_display = '$title'")->fetchColumn();
        $status = fn (string $title) => $this->pdo->query("SELECT status FROM song_matches WHERE song_id = {$song($title)}")->fetchColumn();
        $track = fn (int $trackId) => (int) $this->pdo->query("SELECT id FROM recordings WHERE track_id = $trackId")->fetchColumn();

        $page = $this->get('/matches');
        $this->assertSame(200, $page->status);
        $this->assertContains('Needs review <span class="chip__count">2</span>', $page->body);
        $this->assertContains('Static Love (Acoustic)', $page->body, 'the review queue opens first and shows alternatives');
        $this->assertContains('data-pick="2"', $page->body);
        $this->assertContains('https://music.apple.com/us/album/fixture/1?i=900011', $page->body);
        $this->assertContains('original by Blondie', $this->get('/matches', ['tab' => 'covers'])->body);
        $this->assertContains('Confirm all 3', $this->get('/matches', ['tab' => 'auto'])->body);
        $this->assertSame(200, $this->get('/matches', ['tab' => 'nonsense', 'page' => '99'])->status);

        // Picking an alternative saves at once, as JSON for the keyboard queue…
        $response = $this->post("/matches/{$song('Static Love')}/choose", ['recording' => (string) $track(900012)], json: true);
        $this->assertSame(200, $response->status);
        $this->assertSame('chosen', $status('Static Love'));
        // …but only from the song's own options.
        $this->assertSame(422, $this->post("/matches/{$song('Static Love')}/choose", ['recording' => (string) $track(900001)], json: true)->status);

        // Without JavaScript, forms come back to the same list.
        $response = $this->post("/matches/{$song('Low Earth')}/accept", ['tab' => 'review']);
        $this->assertSame("/matches?tab=review#song-{$song('Low Earth')}", $response->headers['Location']);
        $this->assertSame('chosen', $status('Low Earth'));

        $this->post("/matches/{$song('Heart of Glass')}/choose", ['recording' => (string) $track(900031)]);
        $this->assertSame('chosen', $status('Heart of Glass'), 'using the original artist’s version');

        $this->post("/matches/{$song('Orbit')}/exclude", ['excluded' => '1'], json: true);
        $this->assertContains('Excluded <span class="chip__count">1</span>', $this->get('/matches')->body);

        $this->post("/matches/{$song('Night Drive')}/reopen", ['tab' => 'auto']);
        $this->assertSame('review', $status('Night Drive'));
        $this->assertSame('/matches?tab=chosen', $this->post('/matches/approve-all')->headers['Location']);
        $this->assertSame('chosen', $status('Neon Hours'));

        // A search with the user's own terms; the song shows up again wherever it landed.
        $response = $this->post("/matches/{$song('Signal Fire')}/search", ['terms' => 'signal fire', 'tab' => 'unmatched']);
        $this->assertSame('/matches?tab=unmatched&q=Signal+Fire#song-' . $song('Signal Fire'), $response->headers['Location']);
        $this->assertSame('signal fire', $this->pdo->query("SELECT search_terms FROM song_matches WHERE song_id = {$song('Signal Fire')}")->fetchColumn());

        $show = $this->get("/songs/{$song('Static Love')}")->body;
        $this->assertContains('On Apple Music', $show);
        $this->assertContains('your pick', $show);
        $this->assertContains('name="from" value="song"', $show);
    }

    public function testExportPageAndDownloads(): void
    {
        $this->assertContains('No songs with an Apple Music recording yet', $this->get('/export')->body);

        $this->post('/import/confirm', ['file' => $this->uploadSample()]);
        $services = new \HeardLive\Services(Config::fromArray([], dirname(__DIR__, 2)), $this->pdo);
        $jobId = $services->jobs()->enqueue('scan');
        $services->jobs()->claimNext();
        $services->scanJob()->run($jobId);

        $page = $this->get('/export', ['name' => 'Rós <3', 'size' => '200']);
        $this->assertSame(200, $page->status);
        $this->assertContains('Make the playlist', $page->body);
        $this->assertContains('Rós &lt;3', $page->body);
        $this->assertContains('2 songs aren’t', $page->body, 'the review queue is mentioned');
        $this->assertContains('/export/playlist.csv?part=1&amp;format=transfer&amp;name=R%C3%B3s+%3C3&amp;min=1&amp;order=first&amp;size=200', $page->body);
        $this->assertSame(200, $this->get('/export', ['size' => '7', 'min' => 'x', 'order' => 'x'])->status, 'bad options fall back');

        $csv = $this->get('/export/playlist.csv', ['name' => 'Rós <3', 'size' => '200']);
        $this->assertSame('text/csv; charset=utf-8', $csv->headers['Content-Type']);
        $this->assertSame('attachment; filename="R_s 3.csv"; filename*=UTF-8\'\'R%C3%B3s%203.csv', $csv->headers['Content-Disposition']);
        $lines = explode("\r\n", trim($csv->body));
        $this->assertSame("\u{FEFF}title,artist,album,duration,url", $lines[0]);
        $this->assertSame(4, count($lines));
        $this->assertContains('"Neon Hours","The Midnight Arcade"', $lines[1]);

        // Split into parts, each named for its place.
        $this->pdo->exec("UPDATE song_matches SET status = 'chosen' WHERE status = 'review'");
        $part = $this->get('/export/playlist.csv', ['size' => '200', 'part' => '1', 'min' => '2']);
        $this->assertSame(3, count(explode("\r\n", trim($part->body))));
        $this->assertSame(404, $this->get('/export/playlist.csv', ['part' => '2'])->status);

        $details = $this->get('/export/playlist.csv', ['format' => 'details', 'size' => '0']);
        $this->assertContains('Title,Artist,Album,"Apple Music URL","Apple Music ID","Times Heard"', $details->body);
        $this->assertContains('Heard Live - details.csv', $details->headers['Content-Disposition']);

        $missing = $this->get('/export/missing.csv');
        $this->assertContains('"Signal Fire","Paper Satellites",,1,', $missing->body);
        $this->assertContains('"Not found on Apple Music"', $missing->body);
    }

    public function testStatsPage(): void
    {
        $this->assertContains('No songs yet', $this->get('/stats')->body);

        $this->post('/import/confirm', ['file' => $this->uploadSample()]);
        $services = new \HeardLive\Services(Config::fromArray([], dirname(__DIR__, 2)), $this->pdo);
        $jobId = $services->jobs()->enqueue('scan');
        $services->jobs()->claimNext();
        $services->scanJob()->run($jobId);

        $page = $this->get('/stats')->body;
        $this->assertContains('songs heard live', $page);
        $this->assertContains('From 2 artists across 2 concerts, 2025–2026.', $page);
        $this->assertContains('The 2 shows', $page);
        $this->assertContains('Glow in the Dark Tour', $page);
        $this->assertContains('original by Blondie', $page);
        $this->assertContains('3 of the 7 sets you saw have a setlist', $page);
        $this->assertContains('needs you to pick a setlist', $page);
    }

    public function testReviewClearsAttentionAndFiltersWork(): void
    {
        $this->post('/import/confirm', ['file' => $this->uploadSample()]);
        $vipId = (int) $this->pdo->query("SELECT id FROM events WHERE ca_url LIKE '%vip'")->fetchColumn();

        $data = json_decode($this->post("/events/$vipId/review", [], json: true)->body, true);
        $this->assertSame(false, $data['needs_attention']);
        $this->assertSame(1, $data['stats']['attention']);

        $this->assertSame(303, $this->post('/concerts/review-all', ['filter' => 'attention'])->status);
        $this->assertContains('Nothing needs a look', $this->get('/concerts', ['filter' => 'attention'])->body);
    }
}
