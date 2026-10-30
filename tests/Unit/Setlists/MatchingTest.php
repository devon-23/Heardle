<?php

declare(strict_types=1);

namespace Tests\Unit\Setlists;

use HeardLive\Setlists\ArtistResolver;
use HeardLive\Setlists\SetlistFmClient;
use HeardLive\Setlists\SetlistFmFixtures;
use HeardLive\Setlists\SetlistMatcher;
use HeardLive\Support\ApiCache;
use HeardLive\Support\Http\FixtureHttpClient;
use HeardLive\Support\RateLimiter;
use HeardLive\Support\UsageCounter;
use Tests\TestCase;

/** Resolver and matcher edge cases, each with its own throwaway fixture files. */
final class MatchingTest extends TestCase
{
    private string $dir;
    private SetlistFmClient $client;

    public function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("$this->dir/artists");
        mkdir("$this->dir/setlists");
        mkdir("$this->dir/setlist");
        $pdo = $this->migratedDb();
        $this->client = new SetlistFmClient(
            new FixtureHttpClient($this->dir, SetlistFmFixtures::mapper()),
            'key',
            new RateLimiter($pdo),
            new ApiCache($pdo),
            new UsageCounter($pdo),
            cacheTtlSeconds: 60,
            minIntervalSeconds: 0.0,
        );
    }

    private function fixture(string $path, array $data): void
    {
        file_put_contents("$this->dir/$path", json_encode($data));
    }

    private static function setlist(string $id, string $date, string $venue, string $city, array $songs, bool $withSets = true): array
    {
        $setlist = [
            'id' => $id, 'eventDate' => $date, 'url' => "https://www.setlist.fm/setlist/x/$id.html",
            'venue' => ['name' => $venue, 'city' => ['name' => $city, 'country' => ['name' => 'United States']]],
        ];
        if ($withSets) {
            $setlist['sets'] = ['set' => [['song' => array_map(fn ($s) => ['name' => $s], $songs)]]];
        }
        return $setlist;
    }

    private static function event(array $overrides = []): array
    {
        return $overrides + ['start_date' => '2024-05-01', 'end_date' => null, 'venue' => 'The Hall', 'city' => 'Columbus', 'region' => 'Ohio', 'nonstandard_type' => null];
    }

    public function testSameNamedArtistsWithNoDecidingSetlistAreAmbiguous(): void
    {
        $this->fixture('artists/twin.json', ['artist' => [
            ['mbid' => 'a', 'name' => 'Twin', 'disambiguation' => 'US rock'],
            ['mbid' => 'b', 'name' => 'Twin', 'disambiguation' => 'Swedish pop'],
        ]]);

        $result = (new ArtistResolver($this->client))->resolve('Twin', ['2024-05-01']);

        $this->assertSame('ambiguous', $result['resolution']);
        $this->assertSame(['US rock', 'Swedish pop'], array_column($result['candidates'], 'disambiguation'));
    }

    public function testLooseNameMatchingAndNearMissesAsCandidates(): void
    {
        $this->fixture('artists/half-alive.json', ['artist' => [['mbid' => 'h', 'name' => 'half·alive']]]);
        $this->fixture('artists/judah-the-lion.json', ['artist' => [['mbid' => 'j', 'name' => 'Judah and the Lion']]]);
        $this->fixture('artists/case-barge.json', ['artist' => [['mbid' => 'c', 'name' => 'Case Bargé']]]);
        $this->fixture('artists/jackson-brown.json', ['artist' => [
            ['mbid' => 'x', 'name' => 'Ghost Party'],
            ['mbid' => 'jb', 'name' => 'Jackson Browne'],
        ]]);
        $this->fixture('artists/miley-cyrus.json', ['artist' => [['mbid' => 'm', 'name' => 'MILEY', 'disambiguation' => 'Cyrus']]]);
        $resolver = new ArtistResolver($this->client);

        $this->assertSame('h', $resolver->resolve('half•alive', [])['mbid']);
        $this->assertSame('j', $resolver->resolve('Judah & the Lion', [])['mbid']);
        $this->assertSame('c', $resolver->resolve('Case Barge', [])['mbid'], 'accents ignored');

        $browne = $resolver->resolve('Jackson Brown', []);
        $this->assertSame('unresolved', $browne['resolution']);
        $this->assertSame(['Jackson Browne'], array_column($browne['candidates'], 'name'), 'unrelated names not suggested');

        $miley = $resolver->resolve('Miley Cyrus', []);
        $this->assertSame([['mbid' => 'm', 'name' => 'MILEY', 'disambiguation' => 'Cyrus', 'url' => null]], $miley['candidates']);
    }

    public function testRetriesAndVersusAmpersandSpelling(): void
    {
        // The first search finds nothing; the "&" spelling does.
        $this->fixture('artists/kevin-devine-the-goddamn-band.json', ['artist' => [['mbid' => 'k', 'name' => 'Kevin Devine & The Goddamn Band']]]);

        $result = (new ArtistResolver($this->client))->resolve('Kevin Devine and The Goddamn Band', []);

        $this->assertSame('resolved', $result['resolution']);
        $this->assertSame('k', $result['mbid']);
    }

    public function testOtherCityIsNotAutoMatched(): void
    {
        $this->fixture('setlists/m_01-05-2024.json', ['total' => 1, 'setlist' => [self::setlist('s1', '01-05-2024', 'Arena', 'Detroit', ['A'])]]);

        $result = (new SetlistMatcher($this->client))->match(self::event(), 'm');

        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame('location_mismatch', $result['note']);
        $this->assertSame(null, $result['chosen']);
    }

    public function testOnePlausibleAmongSeveralIsMatched(): void
    {
        $this->fixture('setlists/m_01-05-2024.json', ['total' => 2, 'setlist' => [
            self::setlist('far', '01-05-2024', 'Arena', 'Detroit', ['A']),
            self::setlist('here', '01-05-2024', 'The Hall', 'Columbus', ['A', 'B']),
        ]]);

        $result = (new SetlistMatcher($this->client))->match(self::event(), 'm');

        $this->assertSame('matched', $result['status']);
        $this->assertSame('here', $result['chosen']['id']);
        $this->assertSame('here', $result['candidates'][0]['id'], 'best candidate listed first');
    }

    public function testExactVenueSettlesSameCitySetlists(): void
    {
        $this->fixture('setlists/m_01-05-2024.json', ['total' => 2, 'setlist' => [
            self::setlist('radio', '01-05-2024', 'Radio 104.5 Studio Session', 'Columbus', ['A']),
            self::setlist('show', '01-05-2024', 'The Hall', 'Columbus', ['A', 'B']),
        ]]);
        $this->assertSame('show', (new SetlistMatcher($this->client))->match(self::event(), 'm')['chosen']['id']);

        // Two setlists at the same venue (often a duplicate entry): still your call.
        $this->fixture('setlists/m_02-05-2024.json', ['total' => 2, 'setlist' => [
            self::setlist('one', '02-05-2024', 'The Hall', 'Columbus', ['A']),
            self::setlist('two', '02-05-2024', 'The Hall', 'Columbus', ['A']),
        ]]);
        $this->assertSame('ambiguous', (new SetlistMatcher($this->client))->match(self::event(['start_date' => '2024-05-02']), 'm')['status']);
    }

    public function testSetlistWithNoSongsIsEmpty(): void
    {
        $this->fixture('setlists/m_01-05-2024.json', ['total' => 1, 'setlist' => [self::setlist('s1', '01-05-2024', 'The Hall', 'Columbus', [])]]);

        $this->assertSame('empty', (new SetlistMatcher($this->client))->match(self::event(), 'm')['status']);
    }

    public function testFetchesFullSetlistWhenSearchOmitsSongs(): void
    {
        $this->fixture('setlists/m_01-05-2024.json', ['total' => 1, 'setlist' => [self::setlist('s1', '01-05-2024', 'The Hall', 'Columbus', [], withSets: false)]]);
        $this->fixture('setlist/s1.json', self::setlist('s1', '01-05-2024', 'The Hall', 'Columbus', ['A', 'B', 'C']));

        $result = (new SetlistMatcher($this->client))->match(self::event(), 'm');

        $this->assertSame('matched', $result['status']);
        $this->assertSame(3, $result['chosen']['song_count']);
    }

    public function testUnknownCityOrVenueStillMatches(): void
    {
        $this->fixture('setlists/m_01-05-2024.json', ['total' => 1, 'setlist' => [self::setlist('s1', '01-05-2024', 'Somewhere', 'Youngstown', ['A'])]]);

        $result = (new SetlistMatcher($this->client))->match(self::event(['venue' => null, 'city' => null]), 'm');

        $this->assertSame('matched', $result['status']);
    }
}
