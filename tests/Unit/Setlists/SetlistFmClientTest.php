<?php

declare(strict_types=1);

namespace Tests\Unit\Setlists;

use HeardLive\Setlists\SetlistFmClient;
use HeardLive\Setlists\SetlistFmException;
use HeardLive\Support\ApiCache;
use HeardLive\Support\Http\HttpClient;
use HeardLive\Support\Http\HttpResponse;
use HeardLive\Support\Http\NetworkError;
use HeardLive\Support\RateLimiter;
use HeardLive\Support\UsageCounter;
use PDO;
use Tests\TestCase;

/** Replays a scripted list of responses (or NetworkError) and records what was asked for. */
final class ScriptedHttp implements HttpClient
{
    public array $requests = [];

    public function __construct(private array $script)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        $next = array_shift($this->script) ?? new HttpResponse(500, '');
        if ($next instanceof NetworkError) {
            throw $next;
        }
        return $next;
    }
}

final class SetlistFmClientTest extends TestCase
{
    private PDO $pdo;
    /** @var list<float> backoff sleeps */
    private array $sleeps = [];
    /** @var list<float> rate-limiter waits */
    private array $waits = [];

    public function setUp(): void
    {
        $this->pdo = $this->migratedDb();
        $this->sleeps = [];
        $this->waits = [];
    }

    private function client(ScriptedHttp $http, int $budget = 100): SetlistFmClient
    {
        $sleep = function (float $s): void {
            $this->sleeps[] = $s;
        };
        $wait = function (float $s): void {
            $this->waits[] = $s;
        };

        return new SetlistFmClient(
            http: $http,
            apiKey: 'test-key',
            limiter: new RateLimiter($this->pdo, sleep: $wait),
            cache: new ApiCache($this->pdo),
            usage: new UsageCounter($this->pdo),
            cacheTtlSeconds: 3600,
            minIntervalSeconds: 0.0,
            dailyBudget: $budget,
            maxRetries: 3,
            sleep: $sleep,
        );
    }

    private static function json(array $data, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, json_encode($data), $headers);
    }

    public function testSendsKeyAndJsonAcceptHeaderAndBuildsUrl(): void
    {
        $http = new ScriptedHttp([self::json(['artist' => [['name' => 'A', 'mbid' => 'm']]])]);

        $artists = $this->client($http)->searchArtists('Judah & the Lion');

        $this->assertSame('A', $artists[0]['name']);
        $this->assertSame('test-key', $http->requests[0]['headers']['x-api-key']);
        $this->assertSame('application/json', $http->requests[0]['headers']['Accept']);
        $this->assertSame(
            'https://api.setlist.fm/rest/1.0/search/artists?artistName=Judah%20%26%20the%20Lion&p=1&sort=relevance',
            $http->requests[0]['url'],
        );
    }

    public function testNotFoundMeansNoResultsAndIsCached(): void
    {
        $http = new ScriptedHttp([new HttpResponse(404, '{"code":404}')]);
        $client = $this->client($http);

        $this->assertSame([], $client->setlistsOn('m', '2025-09-21'));
        $this->assertSame([], $client->setlistsOn('m', '2025-09-21'));
        $this->assertSame(1, count($http->requests), 'second call served from cache');
        $this->assertContains('date=21-09-2025', $http->requests[0]['url']);
    }

    public function testPaginatesSetlistSearch(): void
    {
        $http = new ScriptedHttp([
            self::json(['itemsPerPage' => 1, 'total' => 2, 'setlist' => [['id' => 'a']]]),
            self::json(['itemsPerPage' => 1, 'total' => 2, 'setlist' => [['id' => 'b']]]),
        ]);

        $this->assertSame(['a', 'b'], array_column($this->client($http)->setlistsOn('m', '2025-01-01'), 'id'));
    }

    public function testRetriesServerErrorsAndNetworkFailuresWithBackoff(): void
    {
        $http = new ScriptedHttp([
            new HttpResponse(503, ''),
            new NetworkError('timeout'),
            self::json(['artist' => []]),
        ]);

        $this->assertSame([], $this->client($http)->searchArtists('X'));
        $this->assertSame([1, 2], array_map('intval', $this->sleeps));
    }

    public function testRetryAfterOn429DelaysTheNextSlot(): void
    {
        $http = new ScriptedHttp([new HttpResponse(429, '', ['retry-after' => '7']), self::json(['artist' => []])]);

        $this->client($http)->searchArtists('X');

        $this->assertSame(2, count($http->requests));
        $this->assertTrue($this->waits !== [] && max($this->waits) > 6, 'waited for Retry-After');
        $this->assertSame([], $this->sleeps, 'no extra backoff when the server says how long to wait');
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $http = new ScriptedHttp(array_fill(0, 10, new HttpResponse(500, '')));

        $this->assertThrows(SetlistFmException::class, fn () => $this->client($http)->searchArtists('X'), 'kept failing');
        $this->assertSame(4, count($http->requests));
    }

    public function testBadKeyStopsImmediately(): void
    {
        $http = new ScriptedHttp([new HttpResponse(403, '')]);

        try {
            $this->client($http)->searchArtists('X');
            $this->fail('Expected an exception');
        } catch (SetlistFmException $e) {
            $this->assertSame('auth', $e->kind);
            $this->assertTrue($e->stopsTheScan());
        }
    }

    public function testDailyBudgetIsEnforced(): void
    {
        $http = new ScriptedHttp([self::json(['artist' => []]), self::json(['artist' => []])]);
        $client = $this->client($http, budget: 1);

        $client->searchArtists('A');
        try {
            $client->searchArtists('B');
            $this->fail('Expected an exception');
        } catch (SetlistFmException $e) {
            $this->assertSame('budget', $e->kind);
        }
        $this->assertSame(1, count($http->requests));
    }

    public function testCacheExpires(): void
    {
        $cache = new ApiCache($this->pdo, fn () => 1000);
        $cache->put('k', 'setlistfm', new HttpResponse(200, '{}'));

        $this->assertSame('{}', $cache->get('k', 60)->body);
        $later = new ApiCache($this->pdo, fn () => 1061);
        $this->assertSame(null, $later->get('k', 60));
        $this->assertSame(1, $later->prune('setlistfm', 60));
    }

    public function testRateLimiterSpacesRequests(): void
    {
        $now = 100.0;
        $slept = [];
        $limiter = new RateLimiter($this->pdo, fn () => $now, function (float $s) use (&$slept) { $slept[] = $s; });

        $this->assertSame(0.0, $limiter->acquire('p', 1.0));
        $this->assertSame(1.0, $limiter->acquire('p', 1.0));
        $this->assertSame(2.0, $limiter->acquire('p', 1.0));
        $this->assertSame([1.0, 2.0], $slept);
    }
}
