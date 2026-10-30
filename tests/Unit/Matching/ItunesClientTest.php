<?php

declare(strict_types=1);

namespace Tests\Unit\Matching;

use HeardLive\Matching\ItunesClient;
use HeardLive\Matching\ItunesException;
use HeardLive\Support\ApiCache;
use HeardLive\Support\Http\HttpClient;
use HeardLive\Support\Http\HttpResponse;
use HeardLive\Support\Http\NetworkError;
use HeardLive\Support\RateLimiter;
use HeardLive\Support\UsageCounter;
use PDO;
use Tests\TestCase;

/** Replays scripted responses for the iTunes client. */
final class ItunesScript implements HttpClient
{
    public array $urls = [];

    public function __construct(private array $script)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->urls[] = $url;
        $next = array_shift($this->script) ?? new HttpResponse(500, '');
        if ($next instanceof NetworkError) {
            throw $next;
        }
        return $next;
    }
}

final class ItunesClientTest extends TestCase
{
    private PDO $pdo;
    private array $waits = [];

    public function setUp(): void
    {
        $this->pdo = $this->migratedDb();
        $this->waits = [];
    }

    private function client(ItunesScript $http): ItunesClient
    {
        $wait = function (float $s): void {
            $this->waits[] = $s;
        };

        return new ItunesClient($http, new RateLimiter($this->pdo, sleep: $wait), new ApiCache($this->pdo), new UsageCounter($this->pdo),
            minIntervalSeconds: 0.0, maxRetries: 2, sleep: $wait);
    }

    private static function results(array $results, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode(['resultCount' => count($results), 'results' => $results]));
    }

    private static function song(int $id): array
    {
        return ['wrapperType' => 'track', 'kind' => 'song', 'trackId' => $id, 'trackName' => "Song $id", 'artistName' => 'Band'];
    }

    public function testSearchesSongsAndKeepsTheAnswer(): void
    {
        $http = new ItunesScript([self::results([self::song(1), ['wrapperType' => 'track', 'kind' => 'music-video', 'trackId' => 2], self::song(3)])]);
        $client = $this->client($http);

        $this->assertSame([1, 3], array_column($client->search('Band Song'), 'trackId'), 'only songs come back');
        $this->assertSame([1, 3], array_column($client->search('Band Song'), 'trackId'));
        $this->assertSame(1, count($http->urls), 'the second search is answered from the cache');
        $this->assertContains('term=Band%20Song', $http->urls[0]);
        $this->assertContains('entity=song', $http->urls[0]);
        $this->assertContains('country=us', $http->urls[0]);
        $this->assertSame(1, (new UsageCounter($this->pdo))->count('itunes'));
    }

    public function testArtistSearchAndCatalogLookup(): void
    {
        $http = new ItunesScript([
            self::results([['wrapperType' => 'artist', 'artistId' => 5, 'artistName' => 'Band'], self::song(9)]),
            self::results([['wrapperType' => 'artist', 'artistId' => 5, 'artistName' => 'Band'], self::song(1), self::song(2)]),
            self::results([]),
        ]);
        $client = $this->client($http);

        $this->assertSame([5], array_column($client->searchArtists('Band'), 'artistId'));
        $this->assertContains('entity=musicArtist', $http->urls[0]);
        $this->assertSame([1, 2], array_column($client->artistSongs(5), 'trackId'), 'the artist row itself is left out');
        $this->assertContains('https://itunes.apple.com/lookup?country=us&entity=song&id=5&limit=200', $http->urls[1]);

        $client->search('Dixie D’amelio Be Happy');
        $this->assertContains('term=Dixie%20D%27amelio%20Be%20Happy', $http->urls[2], 'curly apostrophes are straightened');
    }

    public function testThrottledRequestsWaitAndRetry(): void
    {
        $http = new ItunesScript([new HttpResponse(403, ''), self::results([self::song(1)])]);

        $this->assertSame([1], array_column($this->client($http)->search('x'), 'trackId'));
        $this->assertSame(2, count($http->urls));
        $this->assertTrue(max($this->waits) >= 59, 'waits about a minute before trying again');
    }

    public function testPersistentThrottlingStopsTheScan(): void
    {
        $http = new ItunesScript(array_fill(0, 3, new HttpResponse(429, '')));
        try {
            $this->client($http)->search('x');
            $this->assertTrue(false, 'expected an exception');
        } catch (ItunesException $e) {
            $this->assertSame('throttled', $e->kind);
            $this->assertTrue($e->stopsTheScan());
        }
    }

    public function testNetworkTroubleIsRetriedThenSkipped(): void
    {
        $http = new ItunesScript([new NetworkError('down'), self::results([self::song(7)])]);
        $this->assertSame([7], array_column($this->client($http)->search('x'), 'trackId'));

        $http = new ItunesScript(array_fill(0, 3, new NetworkError('down')));
        try {
            $this->client($http)->search('y');
            $this->assertTrue(false, 'expected an exception');
        } catch (ItunesException $e) {
            $this->assertSame('temporary', $e->kind);
            $this->assertTrue(!$e->stopsTheScan(), 'one failed search only skips that song');
        }
    }

    public function testBadJsonIsAnError(): void
    {
        try {
            $this->client(new ItunesScript([new HttpResponse(200, 'not json')]))->search('x');
            $this->assertTrue(false, 'expected an exception');
        } catch (ItunesException $e) {
            $this->assertSame('temporary', $e->kind);
        }
    }
}
