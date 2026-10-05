<?php

namespace WpContent\Cli\Tests\Update;

use PHPUnit\Framework\TestCase;
use WpContent\Cli\Update\Version;

final class VersionTest extends TestCase
{
    public function testReleasesAreReadAsTheyAre(): void
    {
        self::assertSame('1.1.2', Version::release('1.1.2'));
        self::assertSame('2.0.0-beta', Version::release('2.0.0-beta'));
        self::assertSame('2.0.0-rc.1', Version::release('v2.0.0-rc.1'));
    }

    public function testAGitDescribeBuildIsReadAsItsBaseTag(): void
    {
        self::assertSame('1.0.2', Version::release('1.0.2-57-g3156e95'));
        self::assertSame('2.0.0-beta', Version::release('2.0.0-beta-3-gabcdef1'));
    }

    public function testOneLeadingVIsDropped(): void
    {
        self::assertSame('2.1.0', Version::release('v2.1.0'));
        self::assertSame('2.1.0', Version::release('V2.1.0'));
        self::assertSame('2.1.0', Version::release('v2.1.0-3-gabc1234'));
        self::assertNull(Version::release('vv2.1.0'), 'only one');

        self::assertSame('2.1.0', Version::display('v2.1.0'));
        self::assertSame('2.1.0-3-gabc1234', Version::display('v2.1.0-3-gabc1234'));
        self::assertSame('2.1.0', Version::display('2.1.0'));
        self::assertSame('@cli_version@', Version::display('@cli_version@'));
        self::assertSame('vendor', Version::display('vendor'), 'a v that does not prefix a number stays');
    }

    public function testAnythingElseIsUnknown(): void
    {
        self::assertNull(Version::release('@cli_version@'));
        self::assertNull(Version::release('3156e95'));
        self::assertNull(Version::release(''));
        self::assertNull(Version::release(null));
    }

    public function testTheMajorIsTheFirstNumber(): void
    {
        self::assertSame(2, Version::major('2.0.0-beta'));
        self::assertSame(10, Version::major('10.1.0'));
    }
}
