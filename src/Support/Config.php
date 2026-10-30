<?php

declare(strict_types=1);

namespace HeardLive\Support;

use InvalidArgumentException;

final readonly class Config
{
    private const MODES = ['fixture', 'live'];

    public function __construct(
        public string $rootPath,
        public string $appEnv,
        public string $dbPath,
        public string $setlistFmApiKey,
        public string $setlistFmMode,
        public string $itunesMode,
        public string $itunesCountry,
        public int $setlistCacheTtlHours,
        public int $setlistFmMinIntervalMs = 1000,
        public int $setlistFmDailyBudget = 1000,
        public int $itunesMinIntervalMs = 3000,
    ) {
    }

    private const KEYS = [
        'APP_ENV', 'DB_PATH', 'SETLISTFM_API_KEY', 'SETLISTFM_MODE', 'ITUNES_MODE', 'ITUNES_COUNTRY',
        'SETLIST_CACHE_TTL_HOURS', 'SETLISTFM_MIN_INTERVAL_MS', 'SETLISTFM_DAILY_BUDGET',
        'ITUNES_MIN_INTERVAL_MS',
    ];

    /** Reads .env; real environment variables win, e.g. `DB_PATH=storage/demo.sqlite bin/dev`. */
    public static function load(string $rootPath): self
    {
        $env = Env::load($rootPath . '/.env');
        foreach (self::KEYS as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        return self::fromArray($env, $rootPath);
    }

    /** @param array<string, string> $env */
    public static function fromArray(array $env, string $rootPath): self
    {
        $setlistFmMode = $env['SETLISTFM_MODE'] ?? 'fixture';
        $itunesMode = $env['ITUNES_MODE'] ?? 'fixture';
        $apiKey = $env['SETLISTFM_API_KEY'] ?? '';
        $ttl = $env['SETLIST_CACHE_TTL_HOURS'] ?? '24';

        foreach (['SETLISTFM_MODE' => $setlistFmMode, 'ITUNES_MODE' => $itunesMode] as $name => $mode) {
            if (!in_array($mode, self::MODES, true)) {
                throw new InvalidArgumentException("$name must be one of: " . implode(', ', self::MODES));
            }
        }
        if ($setlistFmMode === 'live' && $apiKey === '') {
            throw new InvalidArgumentException('SETLISTFM_MODE=live needs SETLISTFM_API_KEY');
        }
        $interval = $env['SETLISTFM_MIN_INTERVAL_MS'] ?? '1000';
        $budget = $env['SETLISTFM_DAILY_BUDGET'] ?? '1000';
        $itunesInterval = $env['ITUNES_MIN_INTERVAL_MS'] ?? '3000';
        $numbers = [
            'SETLIST_CACHE_TTL_HOURS' => $ttl, 'SETLISTFM_MIN_INTERVAL_MS' => $interval,
            'SETLISTFM_DAILY_BUDGET' => $budget, 'ITUNES_MIN_INTERVAL_MS' => $itunesInterval,
        ];
        foreach ($numbers as $name => $value) {
            if (!ctype_digit($value) || (int) $value < 1) {
                throw new InvalidArgumentException("$name must be a positive whole number");
            }
        }

        $dbPath = $env['DB_PATH'] ?? 'storage/app.sqlite';
        if (!str_starts_with($dbPath, '/') && $dbPath !== ':memory:') {
            $dbPath = $rootPath . '/' . $dbPath;
        }

        return new self(
            rootPath: $rootPath,
            appEnv: $env['APP_ENV'] ?? 'local',
            dbPath: $dbPath,
            setlistFmApiKey: $apiKey,
            setlistFmMode: $setlistFmMode,
            itunesMode: $itunesMode,
            itunesCountry: strtolower($env['ITUNES_COUNTRY'] ?? 'us'),
            setlistCacheTtlHours: (int) $ttl,
            setlistFmMinIntervalMs: (int) $interval,
            setlistFmDailyBudget: (int) $budget,
            itunesMinIntervalMs: (int) $itunesInterval,
        );
    }

    public function isLocal(): bool
    {
        return $this->appEnv === 'local';
    }
}
