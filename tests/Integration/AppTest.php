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
