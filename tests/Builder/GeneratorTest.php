<?php

namespace WpContent\Cli\Tests\Builder;

use PHPUnit\Framework\TestCase;
use WpContent\Cli\Builder\Generator;
use WpContent\Cli\ResourceType;
use WpContent\Cli\Tests\TempDirTrait;
use WpContent\Cli\Wordpress\HeaderParser;

final class GeneratorTest extends TestCase
{
    use TempDirTrait;

    public function testAPluginIsScaffoldedIntoADirectoryNamedAfterItsSlug(): void
    {
        $root = $this->makeTempDir();

        $workdir = (new Generator($root))->generateMainFile(ResourceType::Plugin, 'acme-analytics', [
            'Plugin Name' => 'Acme Analytics',
            'Version' => '1.0.0',
        ]);

        // Compared through realpath: the generator answers with the resolved
        // path, and /var is a symlink to /private/var on macOS.
        self::assertSame(realpath("$root/acme-analytics"), $workdir);
        self::assertFileExists("$root/acme-analytics/acme-analytics.php");

        // What was written has to be readable back by the very parser the CLI
        // uses to build the archive.
        $headers = HeaderParser::plugin("$root/acme-analytics/acme-analytics.php");

        self::assertSame('Acme Analytics', $headers['Name']);
        self::assertSame('1.0.0', $headers['Version']);
    }

    public function testAThemeIsScaffoldedAsAStylesheet(): void
    {
        $root = $this->makeTempDir();

        (new Generator($root))->generateMainFile(ResourceType::Theme, 'acme', ['Theme Name' => 'Acme']);

        self::assertFileExists("$root/acme/style.css");
        self::assertSame('Acme', HeaderParser::theme("$root/acme/style.css")['Name']);
    }

    public function testAPluginFileOpensWithItsPhpTag(): void
    {
        $root = $this->makeTempDir();
        (new Generator($root))->generateMainFile(ResourceType::Plugin, 'acme', ['Plugin Name' => 'Acme']);

        self::assertStringStartsWith('<?php', (string) file_get_contents("$root/acme/acme.php"));
    }

    public function testAnUnwritableRootReportsFailureRatherThanThrowing(): void
    {
        $root = $this->makeTempDir();
        chmod($root, 0555);

        try {
            // root ignores the mode entirely, and CI runs as root in the alpine
            // images — so probe rather than assume, or this passes locally and
            // fails everywhere it matters.
            if (@mkdir("$root/probe")) {
                rmdir("$root/probe");

                self::markTestSkipped('The running user writes through read-only directories.');
            }

            $workdir = (new Generator($root))->generateMainFile(ResourceType::Plugin, 'acme', ['Plugin Name' => 'Acme']);

            self::assertNull($workdir);
        } finally {
            // Put it back so the temp directory can be cleaned up.
            chmod($root, 0755);
        }
    }
}
