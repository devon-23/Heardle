<?php

declare(strict_types=1);

namespace HeardLive\Http\Controllers;

use HeardLive\Concerts\EventRepository;
use HeardLive\Http\Controller;
use HeardLive\Http\Request;
use HeardLive\Http\Response;
use HeardLive\Http\View;
use HeardLive\Import\ConcertArchivesCsv;
use HeardLive\Import\CsvFormatError;
use HeardLive\Import\Importer;
use HeardLive\Import\UploadStore;
use PDO;
use RuntimeException;

/** Upload → preview → confirm. Nothing touches the database until confirm. */
final class ImportController extends Controller
{
    public function __construct(View $view, private PDO $pdo, private UploadStore $uploads)
    {
        parent::__construct($view);
    }

    public function form(Request $request, ?string $error = null, int $status = 200): Response
    {
        return $this->page('import/form', 'Import', [
            'error' => $error,
            'hasEvents' => (new EventRepository($this->pdo))->hasEvents(),
        ], $status);
    }

    public function upload(Request $request): Response
    {
        try {
            $token = $this->uploads->store($request->files['csv'] ?? null);
        } catch (RuntimeException $e) {
            return $this->form($request, $e->getMessage(), 422);
        }

        return Response::redirect('/import/preview?file=' . $token);
    }

    public function preview(Request $request): Response
    {
        $token = $request->queryString('file');
        $upload = $this->uploads->get($token);
        if ($upload === null) {
            return $this->form($request, 'That upload has expired. Choose the file again.', 404);
        }

        try {
            $result = ConcertArchivesCsv::parseFile($upload['path']);
        } catch (CsvFormatError $e) {
            $this->uploads->delete($token);
            return $this->form($request, $e->getMessage(), 422);
        }

        $flagged = array_values(array_filter($result->events, fn ($e) => $e->flags !== []));

        return $this->page('import/preview', 'Check your import', [
            'token' => $token,
            'filename' => $upload['original_name'],
            'summary' => $result->summary(),
            'errors' => $result->errors,
            'skipped' => $result->skipped,
            'extraColumns' => $result->extraColumns,
            'flagged' => $flagged,
            'hasEvents' => (new EventRepository($this->pdo))->hasEvents(),
        ]);
    }

    public function confirm(Request $request): Response
    {
        $token = (string) ($request->body['file'] ?? '');
        $upload = $this->uploads->get($token);
        if ($upload === null) {
            return $this->form($request, 'That upload has expired. Choose the file again.', 404);
        }

        try {
            $result = ConcertArchivesCsv::parseFile($upload['path']);
        } catch (CsvFormatError $e) {
            return $this->form($request, $e->getMessage(), 422);
        }
        $outcome = (new Importer($this->pdo))->import($result, $upload['original_name']);
        // The CSV holds personal history; keep only what's in the database.
        $this->uploads->delete($token);

        return Response::redirect(sprintf(
            '/concerts?imported=%d&updated=%d&removed=%d',
            $outcome['created'],
            $outcome['updated'],
            $outcome['deactivated'],
        ));
    }
}
