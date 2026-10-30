<?php

declare(strict_types=1);

namespace HeardLive\Support\Http;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeoutSeconds = 10)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $responseHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_USERAGENT => 'HeardLive/0.1 (personal, non-commercial)',
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            throw new NetworkError("Request failed: $error");
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string) $body, $responseHeaders);
    }
}
