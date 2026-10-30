<?php

declare(strict_types=1);

namespace Tests\Unit\Matching;

use HeardLive\Matching\CandidateScorer;
use HeardLive\Matching\RecordingClassifier;
use Tests\TestCase;

final class RecordingClassifierTest extends TestCase
{
    private static function kindAndTitle(string $track, string $song, string $album = 'Album'): string
    {
        $type = RecordingClassifier::classify($track, $album, $song);

        return $type['kind'] . '/' . $type['title'];
    }

    public function testStudioTitlesWithNeutralLabelsAreExact(): void
    {
        $this->assertSame('studio/exact', self::kindAndTitle('Car Radio', 'car radio'));
        $this->assertSame('studio/exact', self::kindAndTitle('Night Drive (feat. Low Tide Choir)', 'night drive'));
        $this->assertSame('studio/exact', self::kindAndTitle('Heart of Glass (2001 Remaster)', 'heart of glass'));
        $this->assertSame('studio/exact', self::kindAndTitle('Ride - Radio Edit', 'ride'));
        $this->assertSame('studio/exact', self::kindAndTitle('Song Name [feat. A] (Remastered)', 'song name'));
    }

    public function testBracketsThatBelongToTheSongStayPartOfTheTitle(): void
    {
        $this->assertSame('studio/exact', self::kindAndTitle('The Craving (Jenna’s Version)', 'the craving jennas version'));
        $this->assertSame('studio/qualified', self::kindAndTitle('The Craving (Jenna’s Version)', 'the craving'), 'an unknown label is only probably this song');
    }

    public function testRecordingKinds(): void
    {
        $this->assertSame('live/exact', self::kindAndTitle('Car Radio (Live in Mexico City)', 'car radio'));
        $this->assertSame('live/exact', self::kindAndTitle('Car Radio', 'car radio', 'Live at Red Rocks'));
        $this->assertSame('acoustic/exact', self::kindAndTitle('Static Love (Acoustic)', 'static love'));
        $this->assertSame('remix/exact', self::kindAndTitle('Static Love - Night Owl Remix', 'static love'));
        $this->assertSame('demo/exact', self::kindAndTitle('Kitchen Song (voice memo)', 'kitchen song'));
        $this->assertSame('karaoke/exact', self::kindAndTitle('Neon Hours (Originally Performed by X) [Karaoke Version]', 'neon hours'));
        $this->assertSame('karaoke/exact', self::kindAndTitle('Car Radio', 'car radio', 'Lullaby Versions of Twenty One Pilots'));
        $this->assertSame('altered/exact', self::kindAndTitle('Static Love (Sped Up)', 'static love'));
        $this->assertSame('live/exact', self::kindAndTitle('Static Love (Live) [Remastered]', 'static love'), 'the label nearest the title wins');
    }

    public function testMaskedSwearWordsStillMatch(): void
    {
        $this->assertSame('studio/exact', self::kindAndTitle('F**k U', 'fuck u'));
        $this->assertSame('studio/exact', self::kindAndTitle('The Plan (F**k Jobs)', 'the plan fuck jobs'));
        $this->assertSame('studio/exact', self::kindAndTitle('F*****g With My Head', 'fucking with my head'));
        $this->assertSame('studio/none', self::kindAndTitle('F**k U', 'luck you'), 'the mask has to fit the word');
    }

    public function testTitleMatchLevels(): void
    {
        $this->assertSame('studio/fuzzy', self::kindAndTitle('Lowearth', 'low earth'));
        $this->assertSame('studio/none', self::kindAndTitle('Low Earth Orbit', 'low earth'), 'a longer title is a different song');
        $this->assertSame('studio/none', self::kindAndTitle('Reputation', 'mr brightside'));
    }

    public function testAlbumHints(): void
    {
        $type = RecordingClassifier::classify('Ride', 'The Very Best Of', 'ride');
        $this->assertTrue($type['compilation']);
        $this->assertTrue(RecordingClassifier::classify('Ride', 'Ride - Single', 'ride')['single']);
        $this->assertTrue(RecordingClassifier::classify('Ride', 'Blurryface (Deluxe Edition)', 'ride')['deluxe']);
    }

    public function testArtistMatches(): void
    {
        $this->assertSame('exact', CandidateScorer::artistMatch('twenty one pilots', 'Twenty One Pilots'));
        $this->assertSame('exact', CandidateScorer::artistMatch('Judah & The Lion', 'Judah and the Lion'));
        $this->assertSame('exact', CandidateScorer::artistMatch('Killers', 'The Killers'));
        $this->assertSame('credited', CandidateScorer::artistMatch('Cavetown & Tessa Violet', 'Tessa Violet'));
        $this->assertSame('credited', CandidateScorer::artistMatch('Someone feat. Tessa Violet', 'Tessa Violet'));
        $this->assertSame('partial', CandidateScorer::artistMatch('Kevin Devine', 'Kevin Devine & The Goddamn Band'));
        $this->assertSame('partial', CandidateScorer::artistMatch('Dixie', 'Dixie D’Amelio'));
        $this->assertSame('none', CandidateScorer::artistMatch('AJ', 'AJ Mitchell'), 'too short to trust as part of a name');
        $this->assertSame('original', CandidateScorer::artistMatch('Blondie', 'The Midnight Arcade', 'Blondie'));
        $this->assertSame('none', CandidateScorer::artistMatch('Twinkle Twinkle Little Rock Star', 'Twenty One Pilots'));
    }

    public function testOnlyExactArtistTitleAndStudioIsHighConfidence(): void
    {
        $song = ['performer' => 'The Midnight Arcade', 'title_norm' => 'neon hours', 'original_artist_name' => null];
        $result = fn (string $artist, string $track, string $album = 'Signal Lights') => ['artistName' => $artist, 'trackName' => $track, 'collectionName' => $album];

        $this->assertSame('high', CandidateScorer::confidence(CandidateScorer::score($song, $result('The Midnight Arcade', 'Neon Hours'))));
        $this->assertSame('medium', CandidateScorer::confidence(CandidateScorer::score($song, $result('The Midnight Arcade', 'Neon Hours (Live)'))));
        $this->assertSame('medium', CandidateScorer::confidence(CandidateScorer::score($song, $result('The Midnight Arcade', 'Neon Hourz'))));
        $this->assertSame('none', CandidateScorer::confidence(CandidateScorer::score($song, $result('Someone Else', 'Neon Hours'))));
        $this->assertSame(null, CandidateScorer::score($song, $result('The Midnight Arcade', 'Static Love')), 'a different song is dropped');

        $album = CandidateScorer::score($song, $result('The Midnight Arcade', 'Neon Hours'))['score'];
        $single = CandidateScorer::score($song, $result('The Midnight Arcade', 'Neon Hours', 'Neon Hours - Single'))['score'];
        $hits = CandidateScorer::score($song, $result('The Midnight Arcade', 'Neon Hours', 'Greatest Hits'))['score'];
        $this->assertTrue($album > $single && $single > $hits, 'original album beats single beats compilation');
        $various = CandidateScorer::score($song, $result('The Midnight Arcade', 'Neon Hours', 'Modern Rock') + ['collectionArtistName' => 'Various Artists'])['score'];
        $this->assertTrue($single > $various, 'a "Various Artists" album is a compilation whatever it’s called');
    }
}
