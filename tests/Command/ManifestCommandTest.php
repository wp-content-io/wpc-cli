<?php

namespace WpContent\Cli\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use WpContent\Cli\Application;
use WpContent\Cli\Tests\SplitOutput;
use WpContent\Cli\Tests\TempDirTrait;

final class ManifestCommandTest extends TestCase
{
    use TempDirTrait;

    public function testManifestOutputsJson(): void
    {
        $file = $this->pluginFile();

        [$exit, $display] = $this->runCli("plugin:manifest --output=json $file");

        self::assertSame(0, $exit);
        $data = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('My Plugin', $data['Name']);
        self::assertSame('3.2.1', $data['Version']);
    }

    public function testGlobalOutputOptionWorksAfterArguments(): void
    {
        $file = $this->pluginFile();

        // Regression: a global option placed after a command-specific argument
        // used to be silently ignored (the app dropped it while binding only
        // its own options). Config now reads it from the raw tokens.
        [$exit, $display] = $this->runCli("plugin:manifest $file --output=json");

        self::assertSame(0, $exit);
        $data = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('3.2.1', $data['Version']);
    }

    public function testTheV2GrammarReachesTheSameCommand(): void
    {
        $file = $this->pluginFile();

        // StringInput gets the already-merged token, which is what
        // CommandLine::normalize() hands Symfony for `wpc plugin manifest`.
        [$exit, $display] = $this->runCli("'plugin manifest' --output=json $file");

        self::assertSame(0, $exit);
        $data = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('3.2.1', $data['Version']);
    }

    public function testManifestGetSingleProperty(): void
    {
        $file = $this->pluginFile();

        [$exit, $display] = $this->runCli("plugin:manifest --get=Version $file");

        self::assertSame(0, $exit);
        self::assertSame('3.2.1', trim($display));
    }

    /**
     * `--get` is a shell accessor, so human output stays the bare value — but
     * `--output=json` is a contract, and answering it with `3.2.1` handed a
     * parse error to whoever had asked for an object.
     */
    public function testManifestGetAnswersAnObjectUnderJson(): void
    {
        $file = $this->pluginFile();

        [$exit, $display] = $this->runCli("'plugin manifest' --get=Version --output=json $file");

        self::assertSame(0, $exit);
        self::assertSame(['Version' => '3.2.1'], json_decode($display, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * A field name the parser does not know answers empty, with exit 0 — what
     * 1.x did, and what `VAR=$(wpc … --get X)` under `set -e` relies on — but
     * says so on stderr, so a typo like `Verison` does not go unnoticed.
     */
    public function testAnUnknownFieldAnswersEmptyAndSaysSoOnStderr(): void
    {
        [$exit, $stdout, $stderr] = $this->split("'plugin manifest' --get=Verison " . $this->pluginFile());

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Verison', $stderr);
    }

    /**
     * A known header the file leaves out — an optional one like `Requires PHP`
     * — answers empty with exit 0, as in 1.x, with a notice on stderr.
     */
    public function testAHeaderTheFileDoesNotCarryAnswersEmpty(): void
    {
        $dir = $this->makeTempDir();
        $file = "$dir/my-plugin.php";
        $this->writeFile($file, "<?php\n/**\n * Plugin Name: My Plugin\n */\n");

        [$exit, $stdout, $stderr] = $this->split("'plugin manifest' --get=Version $file");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Version', $stderr);

        [$exit, $json, $stderr] = $this->split("'plugin manifest' --get=Version --output=json $file");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['Version' => ''], json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('', $stderr, 'json stays silent: the empty value says it');

        [, , $stderr] = $this->split("'plugin manifest' --get=Version -q $file");

        self::assertSame('', $stderr, '-q silences the notice');
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function split(string $command): array
    {
        $output = new SplitOutput();

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $exit = $application->run(new StringInput($command), $output);

        return [$exit, $output->fetch(), $output->fetchError()];
    }

    /**
     * `--get 0` asks for a header named "0", it does not mean "no --get".
     */
    public function testAFalsyHeaderNameIsStillARequest(): void
    {
        [$exit, $stdout, $stderr] = $this->split("'plugin manifest' --get=0 " . $this->pluginFile());

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('', $stdout, 'the whole manifest is not the answer to that question');
        self::assertStringContainsString('"0"', $stderr);
    }

    /**
     * The value goes out raw: the formatter would otherwise swallow a header
     * reading "requires PHP <8.0" as if it were a tag.
     */
    public function testAHeaderValueIsNotEatenByTheFormatter(): void
    {
        $dir = $this->makeTempDir();
        $file = "$dir/my-plugin.php";
        $this->writeFile($file, "<?php\n/**\n * Plugin Name: My Plugin\n * Requires PHP: <8.0 only\n */\n");

        [$exit, $display] = $this->runCli("'plugin manifest' --get=RequiresPHP $file");

        self::assertSame(0, $exit);
        self::assertSame('<8.0 only', trim($display));
    }

    private function pluginFile(): string
    {
        $dir = $this->makeTempDir();
        $file = "$dir/my-plugin.php";
        $this->writeFile($file, "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 3.2.1\n */\n");

        return $file;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCli(string $args): array
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $output = new BufferedOutput();
        $exit = $app->run(new StringInput($args), $output);

        return [$exit, $output->fetch()];
    }
}
