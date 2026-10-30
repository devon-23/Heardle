<?php

declare(strict_types=1);

namespace HeardLive\Setlists;

use Closure;
use HeardLive\Support\ApiCache;
use HeardLive\Support\Http\HttpClient;
use HeardLive\Support\Http\HttpResponse;
use HeardLive\Support\Http\NetworkError;
use HeardLive\Support\RateLimiter;
use HeardLive\Support\UsageCounter;

/**
 * setlist.fm REST API 1.0. See docs/BUILD_SPEC.md §6.6 for the rules this follows:
 * JSON with x-api-key, spaced-out requests, backoff on 429/5xx, short-term caching only.
 */
final class SetlistFmClient
{
    public const PROVIDER = 'setlistfm';
    public const BASE_URL = 'https://api.setlist.fm/rest/1.0';

    private const MAX_PAGES = 5;

    private Closure $sleep;

    /** @param ?Closure(float): void $sleep used for backoff */
    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private RateLimiter $limiter,
        private ApiCache $cache,
        private UsageCounter $usage,
        private int $cacheTtlSeconds,
        private float $minIntervalSeconds = 1.0,
        private int $dailyBudget = 1000,
        private int $maxRetries = 5,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /** @return list<array<string, mixed>> artists from the first page of a relevance-sorted search */
    public function searchArtists(string $name): array
    {
        $data = $this->request('search/artists', ['artistName' => $name, 'p' => 1, 'sort' => 'relevance']);

        return $data['artist'] ?? [];
    }

    /**
     * @param string $date YYYY-MM-DD
     * @return list<array<string, mixed>> every setlist for that artist on that date
     */
    public function setlistsOn(string $mbid, string $date): array
    {
        $apiDate = (new \DateTimeImmutable($date))->format('d-m-Y');
        $setlists = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->request('search/setlists', ['artistMbid' => $mbid, 'date' => $apiDate, 'p' => $page]);
            $setlists = [...$setlists, ...($data['setlist'] ?? [])];
            $total = (int) ($data['total'] ?? 0);
            $perPage = max(1, (int) ($data['itemsPerPage'] ?? 20));
            if ($data === null || $page * $perPage >= $total) {
                break;
            }
        }

        return $setlists;
    }

    /** @return ?array<string, mixed> */
    public function setlist(string $id): ?array
    {
        return $this->request('setlist/' . rawurlencode($id), []);
    }

    /**
     * @param array<string, string|int> $query
     * @return ?array<string, mixed> decoded JSON, or null for 404 (setlist.fm's answer to an empty search)
     */
    private function request(string $path, array $query): ?array
    {
        ksort($query);
        $url = self::BASE_URL . '/' . $path . ($query === [] ? '' : '?' . http_build_query($query, encoding_type: PHP_QUERY_RFC3986));

        $response = $this->cache->get($url, $this->cacheTtlSeconds) ?? $this->fetch($url);

        if ($response->status === 404) {
            return null;
        }
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new SetlistFmException('temporary', "setlist.fm sent a response that isn’t JSON for $path.");
        }

        return $data;
    }

    private function fetch(string $url): HttpResponse
    {
        $headers = ['Accept' => 'application/json', 'x-api-key' => $this->apiKey];

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($this->usage->count(self::PROVIDER) >= $this->dailyBudget) {
                throw new SetlistFmException('budget', sprintf(
                    'Used today’s budget of %d setlist.fm requests. The scan will pick up where it left off tomorrow.',
                    $this->dailyBudget,
                ));
            }
            $this->limiter->acquire(self::PROVIDER, $this->minIntervalSeconds);
            $this->usage->record(self::PROVIDER);

            try {
                $response = $this->http->get($url, $headers);
            } catch (NetworkError $e) {
                $this->backoff($attempt);
                continue;
            }

            if ($response->status === 200 || $response->status === 404) {
                $this->cache->put($url, self::PROVIDER, $response);
                return $response;
            }
            if ($response->status === 401 || $response->status === 403) {
                throw new SetlistFmException('auth', 'setlist.fm rejected the API key. Check SETLISTFM_API_KEY in .env.');
            }
            if ($response->status === 429) {
                $retryAfter = (int) ($response->header('retry-after') ?? 0);
                if ($retryAfter > 0) {
                    $this->limiter->delayUntil(self::PROVIDER, microtime(true) + $retryAfter);
                    continue;
                }
            }
            if ($response->status === 429 || $response->status >= 500) {
                $this->backoff($attempt);
                continue;
            }

            throw new SetlistFmException('temporary', "setlist.fm answered {$response->status} for $url.");
        }

        throw new SetlistFmException('temporary', 'setlist.fm kept failing after several retries. Try the scan again later.');
    }

    private function backoff(int $attempt): void
    {
        ($this->sleep)(min(60.0, 2 ** $attempt));
    }
}
