<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use HeardLive\Support\Config;
use InvalidArgumentException;
use Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsRunInFixtureModeWithoutCredentials(): void
    {
        $config = Config::fromArray([], '/app');

        $this->assertSame('fixture', $config->setlistFmMode);
        $this->assertSame('fixture', $config->itunesMode);
        $this->assertSame('us', $config->itunesCountry);
        $this->assertSame(24, $config->setlistCacheTtlHours);
        $this->assertSame('/app/storage/app.sqlite', $config->dbPath);
        $this->assertTrue($config->isLocal());
    }

    public function testLiveSetlistModeRequiresApiKey(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => Config::fromArray(['SETLISTFM_MODE' => 'live'], '/app'),
            'SETLISTFM_API_KEY',
        );

        $config = Config::fromArray(['SETLISTFM_MODE' => 'live', 'SETLISTFM_API_KEY' => 'k'], '/app');
        $this->assertSame('live', $config->setlistFmMode);
    }

    public function testRejectsUnknownMode(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => Config::fromArray(['ITUNES_MODE' => 'mock'], '/app'));
    }

    public function testRejectsBadTtl(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => Config::fromArray(['SETLIST_CACHE_TTL_HOURS' => '0'], '/app'));
        $this->assertThrows(InvalidArgumentException::class, fn () => Config::fromArray(['SETLIST_CACHE_TTL_HOURS' => 'abc'], '/app'));
    }

    public function testDbPathCanBeAbsoluteOrMemory(): void
    {
        $this->assertSame('/tmp/x.sqlite', Config::fromArray(['DB_PATH' => '/tmp/x.sqlite'], '/app')->dbPath);
        $this->assertSame(':memory:', Config::fromArray(['DB_PATH' => ':memory:'], '/app')->dbPath);
    }
}
