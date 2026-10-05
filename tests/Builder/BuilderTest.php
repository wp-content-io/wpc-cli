<?php

namespace WpContent\Cli\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use WpContent\Cli\Builder\Builder;
use WpContent\Cli\Builder\BuildError;
use WpContent\Cli\Builder\NullProgress;
use WpContent\Cli\ResourceType;
use WpContent\Cli\Tests\TempDirTrait;
use ZipArchive;

final class BuilderTest extends TestCase
{
    use TempDirTrait;

    public function testGeneratesZipWithSlugPrefixedEntries(): void
    {
        $source = $this->makeTempDir();
        $this->writeFile("$source/my-plugin.php", "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.0.0\n */\n");
        $this->writeFile("$source/assets/app.js", 'console.log(1);');
        $output = $this->makeTempDir();

        $zipPath = Builder::for(ResourceType::Plugin, 'my-plugin')->generate($source, $output, new NullProgress());

        self::assertIsString($zipPath);
        self::assertFileExists($zipPath);

        $entries = $this->zipEntries($zipPath);
        self::assertContains('my-plugin/my-plugin.php', $entries);
        self::assertContains('my-plugin/assets/app.js', $entries);
    }

    /**
     * A PHP without ext-zip used to die on `new ZipArchive()` with a fatal
     * error. It is now a failure like any other: a message, exit 1, and the
     * `{code, message, errors}` object under `--output=json`.
     */
    public function testAMissingZipExtensionIsAFailureNotAFatal(): void
    {
        $source = $this->makeTempDir();
        $this->writeFile("$source/my-plugin.php", "<?php\n/**\n * Plugin Name: My Plugin\n */\n");
        $output = $this->makeTempDir();

        try {
            Builder::for(ResourceType::Plugin, 'my-plugin', zipAvailable: static fn (): bool => false)
                ->generate($source, $output, new NullProgress());
            self::fail('a build without ext-zip must fail');
        } catch (BuildError $e) {
            self::assertSame('The zip PHP extension is required to build an archive.', $e->getMessage());
            self::assertSame(Command::FAILURE, $e->exitCode());

            $json = new BufferedOutput();
            $e->json($json);
            self::assertSame(
                ['code' => 1, 'message' => $e->getMessage(), 'errors' => []],
                json_decode($json->fetch(), true, flags: JSON_THROW_ON_ERROR)
            );
        }

        self::assertSame([], glob("$output/*"), 'nothing must be written');
    }

    public function testHeaderOverrideRewritesMainFile(): void
    {
        $source = $this->makeTempDir();
        $this->writeFile("$source/my-plugin.php", "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.0.0\n */\n");
        $output = $this->makeTempDir();

        $zipPath = Builder::for(ResourceType::Plugin, 'my-plugin')
            ->setHeader('Version', '2.5.0')
            ->generate($source, $output, new NullProgress());

        $zip = new ZipArchive();
        $zip->open((string) $zipPath);
        $contents = $zip->getFromName('my-plugin/my-plugin.php');
        $zip->close();

        self::assertIsString($contents);
        self::assertStringContainsString('Version: 2.5.0', $contents);
        self::assertStringNotContainsString('Version: 1.0.0', $contents);
    }

    public function testHeaderOverridePreservesDollarSignsLiterally(): void
    {
        $source = $this->makeTempDir();
        $this->writeFile("$source/my-plugin.php", "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.0.0\n */\n");
        $output = $this->makeTempDir();

        // '$1' would be read as a backreference by preg_replace; it must stay literal.
        $zipPath = Builder::for(ResourceType::Plugin, 'my-plugin')
            ->setHeader('Version', '$1.2.3')
            ->generate($source, $output, new NullProgress());

        $zip = new ZipArchive();
        $zip->open((string) $zipPath);
        $contents = (string) $zip->getFromName('my-plugin/my-plugin.php');
        $zip->close();

        self::assertStringContainsString('Version: $1.2.3', $contents);
    }

    public function testEmptyDirectoriesAreIncludedInArchive(): void
    {
        $source = $this->makeTempDir();
        $this->writeFile("$source/my-plugin.php", "<?php\n/**\n * Plugin Name: My Plugin\n */\n");
        mkdir("$source/cache");
        $output = $this->makeTempDir();

        $zipPath = Builder::for(ResourceType::Plugin, 'my-plugin')->generate($source, $output, new NullProgress());

        self::assertContains('my-plugin/cache/', $this->zipEntries($zipPath));
    }

    /**
     * @return list<string>
     */
    private function zipEntries(string $zipPath): array
    {
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $entries;
    }
}
