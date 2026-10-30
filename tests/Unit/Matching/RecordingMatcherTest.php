<?php

declare(strict_types=1);

namespace Tests\Unit\Matching;

use HeardLive\Matching\ItunesClient;
use HeardLive\Matching\RecordingMatcher;
use HeardLive\Support\ApiCache;
use HeardLive\Support\Http\HttpClient;
use HeardLive\Support\Http\HttpResponse;
use HeardLive\Support\RateLimiter;
use HeardLive\Support\UsageCounter;
use Tests\TestCase;

/** Answers by URL; anything not listed is an empty result. */
final class ItunesByUrl implements HttpClient
{
    public array $urls = [];

    /** @param array<string, list<array<string, mixed>>> $routes URL substring → results */
    public function __construct(private array $routes)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->urls[] = $url;
        foreach ($this->routes as $needle => $results) {
            if (str_contains($url, $needle)) {
                return new HttpResponse(200, json_encode(['resultCount' => count($results), 'results' => $results]));
            }
        }
        return new HttpResponse(200, '{"resultCount":0,"results":[]}');
    }
}

final class RecordingMatcherTest extends TestCase
{
    private static function song(int $id, string $title, string $album = 'Album'): array
    {
        return ['kind' => 'song', 'trackId' => $id, 'trackName' => $title, 'artistName' => 'Big Band', 'collectionName' => $album, 'releaseDate' => '2020-01-01T00:00:00Z'];
    }

    private function matcher(ItunesByUrl $http): RecordingMatcher
    {
        $pdo = $this->migratedDb();

        return new RecordingMatcher(new ItunesClient($http, new RateLimiter($pdo), new ApiCache($pdo), new UsageCounter($pdo), minIntervalSeconds: 0.0));
    }

    public function testLargeCatalogsAlsoCheckTheNewestSongs(): void
    {
        $filler = array_map(fn (int $i) => self::song($i, "Filler $i"), range(1, 200));
        $http = new ItunesByUrl([
            'entity=musicArtist' => [['wrapperType' => 'artist', 'artistId' => 42, 'artistName' => 'Big Band']],
            'id=42&limit=200&sort=recent' => [self::song(500, 'Deep Cut')],
            'id=42&limit=200' => $filler,
        ]);
        $song = ['performer' => 'Big Band', 'title_display' => 'Deep Cut', 'title_norm' => 'deep cut', 'original_artist_name' => null];

        $matcher = $this->matcher($http);
        $result = $matcher->match($song);
        $this->assertSame('auto', $result['status']);
        $this->assertSame(500, $result['candidates'][0]['result']['trackId']);
        $this->assertSame(0, count(array_filter($http->urls, fn ($u) => str_contains($u, 'entity=song&limit=50'))), 'no song search needed');

        $this->assertSame('auto', $matcher->match(['title_display' => 'Filler 3', 'title_norm' => 'filler 3'] + $song)['status']);
        $this->assertSame(1, count(array_filter($http->urls, fn ($u) => str_contains($u, 'sort=recent'))), 'each artist’s catalog is fetched once per run');
    }
}
