<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use HeardLive\Support\Env;
use InvalidArgumentException;
use Tests\TestCase;

final class EnvTest extends TestCase
{
    public function testParsesKeysValuesAndSkipsComments(): void
    {
        $env = Env::parse("# comment\n\nAPP_ENV=local\nSETLISTFM_MODE=fixture   # inline note\n");

        $this->assertSame(['APP_ENV' => 'local', 'SETLISTFM_MODE' => 'fixture'], $env);
    }

    public function testQuotedValuesKeepHashesAndSpaces(): void
    {
        $env = Env::parse("A=\"has # hash\"\nB='single quoted'\nC=");

        $this->assertSame(['A' => 'has # hash', 'B' => 'single quoted', 'C' => ''], $env);
    }

    public function testHashWithoutLeadingSpaceIsPartOfValue(): void
    {
        $this->assertSame(['KEY' => 'abc#123'], Env::parse('KEY=abc#123'));
    }

    public function testRejectsLineWithoutEquals(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => Env::parse("JUSTAKEY"), 'line 1');
    }

    public function testRejectsInvalidKey(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => Env::parse("ok=1"), 'invalid key');
    }

    public function testMissingFileLoadsEmpty(): void
    {
        $this->assertSame([], Env::load($this->tempDir() . '/.env'));
    }
}
