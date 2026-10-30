<?php

declare(strict_types=1);

namespace Tests\Unit\Songs;

use HeardLive\Songs\SetlistSongs;
use HeardLive\Songs\TitleNormalizer;
use Tests\TestCase;

final class SetlistSongsTest extends TestCase
{
    private static function key(string $title): string
    {
        return TitleNormalizer::normalize($title)['key'];
    }

    public function testFormattingDifferencesShareAKey(): void
    {
        $this->assertSame(self::key('Car Radio'), self::key('car radio'));
        $this->assertSame(self::key('Don’t Stop Me Now'), self::key("Don't Stop Me Now"));
        $this->assertSame(self::key('The End.'), self::key('The End'));
        $this->assertSame(self::key('Déjà Vu'), self::key('Deja Vu'));
        $this->assertSame(self::key('Unknown/Nth'), self::key('Unknown / Nth'));
        $this->assertSame(self::key('Rock & Roll'), self::key('Rock and Roll'));
        $this->assertSame('turning out part 2', self::key('Turning Out Pt. ii'));
        $this->assertSame(self::key('De Selby (Part 2)'), self::key('De Selby (pt 2)'));
    }

    public function testRealBracketedTitlesStayDistinct(): void
    {
        $this->assertSame('im not okay i promise', self::key("I'm Not Okay (I Promise)"));
        $this->assertTrue(self::key('The Craving (Jenna’s Version)') !== self::key('The Craving'));
        $this->assertTrue(self::key('Kitchen Song (voice memo)') !== self::key('Kitchen Song'));
    }

    public function testVersionLabelsBecomeVariants(): void
    {
        $this->assertSame(['key' => 'heavydirtysoul', 'variant' => 'acoustic'], TitleNormalizer::normalize('Heavydirtysoul (Acoustic)'));
        $this->assertSame(['key' => 'migraine', 'variant' => 'piano version'], TitleNormalizer::normalize('Migraine [Piano Version]'));
    }

    public function testExtractsSongsWithSetPositionsEncoresAndFlags(): void
    {
        $setlist = ['sets' => ['set' => [
            ['song' => [
                ['name' => 'Intro', 'tape' => true],
                ['name' => 'Heathens'],
                ['name' => 'Ride', 'info' => 'acoustic'],
                ['name' => 'Lane Boy', 'info' => 'shortened; >'],
                ['name' => 'Holding On to You', 'info' => 'with snippet of "Cut My Lip"'],
                ['name' => ''],
                ['name' => 'Morph / The Hype', 'info' => '>'],
                ['name' => 'Mr. Brightside', 'cover' => ['mbid' => 'k1', 'name' => 'The Killers'], 'with' => ['name' => 'Guest']],
            ]],
            ['encore' => 1, 'song' => [['name' => 'Trees'], ['name' => 'heathens', 'info' => 'Reprise']]],
        ]]];

        $songs = SetlistSongs::from($setlist);
        $byTitle = array_column($songs, null, 'title');

        $this->assertSame(['Heathens', 'Ride', 'Lane Boy', 'Holding On to You', 'Morph', 'The Hype', 'Mr. Brightside', 'Trees'], array_column($songs, 'title'));
        $this->assertSame([1, 2, 3, 4, 5, 5, 6, 7], array_column($songs, 'position'), 'tape and blank entries skipped; medley parts share a slot');
        $this->assertSame(2, $byTitle['Heathens']['times_in_show'], 'reprise counted once for the show');
        $this->assertSame('acoustic', $byTitle['Ride']['variant']);
        $this->assertSame(true, $byTitle['Lane Boy']['partial']);
        $this->assertSame(false, $byTitle['Holding On to You']['partial'], 'a snippet of another song isn’t this song being partial');
        $this->assertSame([true, true], [$byTitle['Morph']['medley'], $byTitle['The Hype']['medley']]);
        $this->assertSame(false, $byTitle['Heathens']['medley']);
        $this->assertSame(['mbid:k1', 'The Killers', 'Guest'], [$byTitle['Mr. Brightside']['original_key'], $byTitle['Mr. Brightside']['original_name'], $byTitle['Mr. Brightside']['with_artist']]);
        $this->assertSame(1, $byTitle['Trees']['encore']);
        $this->assertSame(null, $byTitle['Heathens']['encore']);
    }

    public function testOnlySpacedSlashesSplitAndRepeatsAcrossMedleysCount(): void
    {
        $songs = SetlistSongs::from(['sets' => ['set' => [['song' => [
            ['name' => 'Needing/Getting'],
            ['name' => 'Unknown/Nth'],
            ['name' => 'Addict With a Pen / Migraine / Forest'],
            ['name' => 'Migraine'],
        ]]]]]);
        $byTitle = array_column($songs, null, 'title');

        $this->assertSame(['Needing/Getting', 'Unknown/Nth', 'Addict With a Pen', 'Migraine', 'Forest'], array_column($songs, 'title'));
        $this->assertSame(false, $byTitle['Needing/Getting']['medley']);
        $this->assertSame(2, $byTitle['Migraine']['times_in_show'], 'in the medley and again in full');
    }

    public function testCoverAndOriginalOfSameTitleStaySeparate(): void
    {
        $songs = SetlistSongs::from(['sets' => ['set' => [['song' => [
            ['name' => 'Creep'],
            ['name' => 'Creep', 'cover' => ['name' => 'Radiohead']],
        ]]]]]);

        $this->assertSame(['', 'name:radiohead'], array_column($songs, 'original_key'));
    }
}
