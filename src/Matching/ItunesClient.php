<?php

declare(strict_types=1);

namespace HeardLive\Matching;

use Closure;
use HeardLive\Support\ApiCache;
use HeardLive\Support\Http\HttpClient;
use HeardLive\Support\Http\HttpResponse;
use HeardLive\Support\Http\NetworkError;
use HeardLive\Support\RateLimiter;
use HeardLive\Support\UsageCounter;

/**
 * iTunes Search API (no key). See docs/BUILD_SPEC.md §8.1: about 20 requests a minute, and Apple
 * asks heavy users to cache, so responses are kept until refreshed by hand.
 */
final class ItunesClient
{
    public const PROVIDER = 'itunes';
    public const BASE_URL = 'https://itunes.apple.com';
    public const LIMIT = 50;
    public const CATALOG_LIMIT = 200; // the most lookup returns

    private const KEEP_SECONDS = 10 * 365 * 24 * 3600;
    // What Apple sends when it's limiting requests isn't documented; treat all of these as "slow down".
    private const THROTTLED = [403, 429, 503];
    private const THROTTLE_PAUSE = 60.0;

    private Closure $sleep;

    /** @param ?Closure(float): void $sleep used for backoff */
    public function __construct(
        private HttpClient $http,
        private RateLimiter $limiter,
        private ApiCache $cache,
        private UsageCounter $usage,
        private string $country = 'us',
        private float $minIntervalSeconds = 3.0,
        private int $maxRetries = 4,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /** @return list<array<string, mixed>> songs matching the terms, in Apple's order (popular songs first, not best match) */
    public function search(string $term): array
    {
        $results = $this->get(self::BASE_URL . '/search', ['entity' => 'song', 'limit' => self::LIMIT, 'media' => 'music', 'term' => self::term($term)]);

        return self::songs($results);
    }

    /** @return list<array<string, mixed>> artists whose names match, with artistId and artistName */
    public function searchArtists(string $name): array
    {
        $results = $this->get(self::BASE_URL . '/search', ['entity' => 'musicArtist', 'limit' => 10, 'media' => 'music', 'term' => self::term($name)]);

        return array_values(array_filter($results, fn ($r) => is_array($r) && ($r['wrapperType'] ?? '') === 'artist' && isset($r['artistId'], $r['artistName'])));
    }

    /**
     * Up to 200 of an artist's songs. This finds album tracks a search misses: search ranks the
     * artist's popular songs above an exact title. $recent asks for the newest 200 instead, which
     * for a large catalog is a different set.
     *
     * @return list<array<string, mixed>>
     */
    public function artistSongs(int $artistId, bool $recent = false): array
    {
        $query = ['entity' => 'song', 'id' => $artistId, 'limit' => self::CATALOG_LIMIT] + ($recent ? ['sort' => 'recent'] : []);

        return self::songs($this->get(self::BASE_URL . '/lookup', $query));
    }

    /** Apple's search doesn't treat curly quotes as apostrophes: "D’amelio" finds nothing, "D'amelio" does. */
    private static function term(string $term): string
    {
        return strtr(trim($term), ['’' => "'", '‘' => "'", '“' => '"', '”' => '"']);
    }

    /**
     * @param array<string, string|int> $query
     * @return list<mixed> the response's results
     */
    private function get(string $endpoint, array $query): array
    {
        $query += ['country' => $this->country];
        ksort($query);
        $url = $endpoint . '?' . http_build_query($query, encoding_type: PHP_QUERY_RFC3986);

        $response = $this->cache->get($url, self::KEEP_SECONDS) ?? $this->fetch($url);
        if ($response->status === 404) {
            return []; // only fixture mode answers 404 (no saved file); the real API sends an empty list
        }
        $data = json_decode($response->body, true);
        if (!is_array($data) || !is_array($data['results'] ?? null)) {
            throw new ItunesException('temporary', 'Apple sent a response that isn’t JSON.');
        }

        return $data['results'];
    }

    /** @param list<mixed> $results @return list<array<string, mixed>> */
    private static function songs(array $results): array
    {
        return array_values(array_filter(
            $results,
            fn ($r) => is_array($r) && ($r['kind'] ?? '') === 'song' && isset($r['trackId'], $r['trackName'], $r['artistName']),
        ));
    }

    private function fetch(string $url): HttpResponse
    {
        $throttled = false;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $throttled = false;
            $this->limiter->acquire(self::PROVIDER, $this->minIntervalSeconds);
            $this->usage->record(self::PROVIDER);

            try {
                $response = $this->http->get($url, ['Accept' => 'application/json']);
            } catch (NetworkError) {
                ($this->sleep)(min(60.0, 2 ** $attempt));
                continue;
            }

            if ($response->status === 200 || $response->status === 404) {
                $this->cache->put($url, self::PROVIDER, $response);
                return $response;
            }
            if (in_array($response->status, self::THROTTLED, true)) {
                $throttled = true;
                $retryAfter = (int) ($response->header('retry-after') ?? 0);
                $this->limiter->delayUntil(self::PROVIDER, microtime(true) + max($retryAfter, self::THROTTLE_PAUSE));
                continue;
            }
            if ($response->status >= 500) {
                ($this->sleep)(min(60.0, 2 ** $attempt));
                continue;
            }

            throw new ItunesException('temporary', "Apple answered {$response->status} to a request.");
        }

        if ($throttled) {
            throw new ItunesException('throttled', 'Apple is limiting searches right now. Scan again in a few minutes to carry on.');
        }

        throw new ItunesException('temporary', 'An Apple Music request kept failing.');
    }
}
