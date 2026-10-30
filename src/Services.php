<?php

declare(strict_types=1);

namespace HeardLive;

use HeardLive\Jobs\JobQueue;
use HeardLive\Jobs\ScanJob;
use HeardLive\Setlists\ArtistResolver;
use HeardLive\Setlists\ScanRepository;
use HeardLive\Setlists\SetlistFmClient;
use HeardLive\Setlists\SetlistFmFixtures;
use HeardLive\Setlists\SetlistMatcher;
use HeardLive\Songs\SongExtractor;
use HeardLive\Songs\SongRepository;
use HeardLive\Support\ApiCache;
use HeardLive\Support\Config;
use HeardLive\Support\Db;
use HeardLive\Support\Http\CurlHttpClient;
use HeardLive\Support\Http\FixtureHttpClient;
use HeardLive\Support\Http\HttpClient;
use HeardLive\Support\RateLimiter;
use HeardLive\Support\UsageCounter;
use PDO;

/** Builds the app's shared objects from config. Shared by the web app and the worker. */
final class Services
{
    private ?SetlistFmClient $setlistFm = null;

    public function __construct(
        public readonly Config $config,
        private ?PDO $pdo = null,
        private ?HttpClient $setlistFmHttp = null,
    ) {
    }

    public function db(): PDO
    {
        return $this->pdo ??= Db::connect($this->config->dbPath);
    }

    public function jobs(): JobQueue
    {
        return new JobQueue($this->db());
    }

    public function scanRepository(): ScanRepository
    {
        return new ScanRepository($this->db());
    }

    public function setlistFm(): SetlistFmClient
    {
        if ($this->setlistFm !== null) {
            return $this->setlistFm;
        }
        $fixture = $this->config->setlistFmMode === 'fixture';
        $http = $this->setlistFmHttp ?? ($fixture
            ? new FixtureHttpClient($this->config->rootPath . '/tests/fixtures/setlistfm', SetlistFmFixtures::mapper())
            : new CurlHttpClient());

        return $this->setlistFm = new SetlistFmClient(
            http: $http,
            apiKey: $this->config->setlistFmApiKey,
            limiter: new RateLimiter($this->db()),
            cache: new ApiCache($this->db()),
            usage: new UsageCounter($this->db()),
            cacheTtlSeconds: $this->config->setlistCacheTtlHours * 3600,
            // Fixtures are local files: no need to pace them.
            minIntervalSeconds: $fixture ? 0.0 : $this->config->setlistFmMinIntervalMs / 1000,
            dailyBudget: $this->config->setlistFmDailyBudget,
        );
    }

    public function songs(): SongRepository
    {
        return new SongRepository($this->db());
    }

    public function scanJob(): ScanJob
    {
        $client = $this->setlistFm();

        return new ScanJob(
            $this->jobs(),
            $this->scanRepository(),
            new ArtistResolver($client),
            new SetlistMatcher($client),
            $this->songs(),
            new SongExtractor($client, $this->songs()),
            $this->config->setlistFmMode,
        );
    }
}
