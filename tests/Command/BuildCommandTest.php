<?php

namespace WpContent\Cli\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use WpContent\Cli\Application;
use WpContent\Cli\Tests\SplitOutput;
use WpContent\Cli\Tests\TempDirTrait;

final class BuildCommandTest extends TestCase
{
    use TempDirTrait;

    public function testBuildProducesZipArtifact(): void
    {
        $source = $this->makeTempDir();
        $this->writeFile("$source/my-plugin.php", "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.0.0\n */\n");
        $outputDir = $this->makeTempDir();

        $app = new Application();
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $output = new BufferedOutput();

        $exit = $app->run(
            new StringInput("plugin:build --output=json --slug=my-plugin --output-dir=$outputDir $source"),
            $output
        );

        self::assertSame(0, $exit);
        $data = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('artifact', $data);
        self::assertFileExists($data['artifact']);
        self::assertStringEndsWith('my-plugin.zip', $data['artifact']);
    }

    /**
     * An option given no value is not an option that was not given.
     *
     * `--slug=` is what a CI job produces from a variable it never set, and it
     * fell past the `??` guard: the archive was built with an empty slug, its
     * entries rooted at "/", under a hidden `.zip` — reported as a success.
     * Guessing the directory name here would be no better, since it would
     * publish the right archive under someone else's name.
     *
     * @return iterable<string, array{string}>
     */
    public static function emptyOptions(): iterable
    {
        yield 'slug' => ['--slug='];
        yield 'filename' => ['--filename='];
    }

    #[DataProvider('emptyOptions')]
    public function testAnOptionGivenNoValueIsAUsageError(string $option): void
    {
        [$exit, $stdout] = $this->build("$option " . $this->pluginSource());

        self::assertSame(Command::INVALID, $exit, "\"$option\" is a usage error");
        self::assertSame('', $stdout, 'nothing may be collected as if it were an artifact');
    }

    /**
     * The archive lands in --output-dir, wherever --filename points.
     */
    public function testAFilenameMayNotBeAPath(): void
    {
        [$exit] = $this->build('--filename=../escaped.zip ' . $this->pluginSource());

        self::assertSame(Command::INVALID, $exit);
    }

    /**
     * The same cause, the same code, with or without `--slug`.
     *
     * `--slug` skips the directory guess, which was the only thing raising the
     * missing-source-directory failure as a usage error — so a typo'd path
     * exited 2 on its own and 1 with the option, and a CI step branching on the
     * two retried the typo forever.
     */
    public function testAMissingSourceDirectoryIsAUsageErrorWhicheverWayItIsCalled(): void
    {
        foreach (['', '--slug=acme'] as $option) {
            [$exit] = $this->build(trim("$option /nope/nope"));

            self::assertSame(Command::INVALID, $exit, "\"$option\" must report invalid usage");
        }
    }

    /**
     * A `--header` that is not `Name=value` is refused, not ignored.
     *
     * `--header "Version: 1.0.1"` matched no header, and the archive went out
     * with the version from the file — the one the override was there to replace.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'colon instead of equals' => ['--header="Version: 1.0.1"'];
        yield 'no value at all' => ['--header=Version'];
        yield 'no name' => ['--header="=1.0.1"'];
    }

    #[DataProvider('malformedHeaders')]
    public function testAMalformedHeaderIsAUsageError(string $option): void
    {
        [$exit, $stdout] = $this->build("$option " . $this->pluginSource());

        self::assertSame(Command::INVALID, $exit);
        self::assertSame('', $stdout, 'no artifact may be reported');
    }

    public function testAHeaderValueMayContainAnEqualsSign(): void
    {
        [$exit] = $this->build('--header="Plugin URI=https://acme.test/?a=b" ' . $this->pluginSource());

        self::assertSame(Command::SUCCESS, $exit);
    }

    private function pluginSource(): string
    {
        $source = $this->makeTempDir();
        $this->writeFile("$source/my-plugin.php", "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.0.0\n */\n");

        return $source;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function build(string $arguments): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        $output = new SplitOutput();
        $outputDir = $this->makeTempDir();
        $exit = $application->run(new StringInput("'plugin build' --output-dir=$outputDir $arguments"), $output);

        return [$exit, $output->fetch()];
    }
}
