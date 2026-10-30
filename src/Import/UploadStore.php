<?php

declare(strict_types=1);

namespace HeardLive\Import;

use RuntimeException;

/** Keeps an uploaded CSV between the preview and confirm steps, under a random token. */
final class UploadStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param bool $requireHttpUpload false only in tests, where files don't come through PHP's upload handling
     */
    public function __construct(private string $dir, private bool $requireHttpUpload = true)
    {
    }

    /**
     * @param array<string, mixed>|null $file one entry from $_FILES
     * @return string token
     * @throws RuntimeException with a user-facing message
     */
    public function store(?array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Choose your Concert Archives CSV to upload.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('That file is larger than 5 MB. A Concert Archives export should be much smaller.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The upload didn’t complete. Try again.');
        }

        $original = (string) ($file['name'] ?? '');
        if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('That isn’t a .csv file. Use Export To Spreadsheet in Concert Archives.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($this->requireHttpUpload && !is_uploaded_file($tmp)) {
            throw new RuntimeException('The upload didn’t complete. Try again.');
        }

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        $token = bin2hex(random_bytes(16));
        $moved = $this->requireHttpUpload ? move_uploaded_file($tmp, $this->csvPath($token)) : copy($tmp, $this->csvPath($token));
        if (!$moved) {
            throw new RuntimeException('The upload couldn’t be saved.');
        }
        file_put_contents($this->metaPath($token), json_encode(['original_name' => basename($original)]));

        return $token;
    }

    /** @return ?array{path: string, original_name: string} */
    public function get(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($this->csvPath($token))) {
            return null;
        }
        $meta = json_decode((string) @file_get_contents($this->metaPath($token)), true);

        return ['path' => $this->csvPath($token), 'original_name' => (string) ($meta['original_name'] ?? 'export.csv')];
    }

    public function delete(string $token): void
    {
        if ($this->get($token) !== null) {
            @unlink($this->csvPath($token));
            @unlink($this->metaPath($token));
        }
    }

    private function csvPath(string $token): string
    {
        return "{$this->dir}/$token.csv";
    }

    private function metaPath(string $token): string
    {
        return "{$this->dir}/$token.json";
    }
}
