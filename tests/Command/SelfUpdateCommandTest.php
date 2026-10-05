<?php

namespace WpContent\Cli\Tests\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use WpContent\Cli\Application;
use WpContent\Cli\Command\SelfUpdate;
use WpContent\Cli\Results\OutputRouter;
use WpContent\Cli\Runtime;
use WpContent\Cli\Tests\SplitOutput;
use WpContent\Cli\Tests\TempDirTrait;
use WpContent\Cli\Update\Updater;

final class SelfUpdateCommandTest extends TestCase
{
    use TempDirTrait;

    public function testSelfUpdateIsRegisteredWithItsOptions(): void
    {
        $definition = (new Application())->find('self-update')->getDefinition();

        self::assertTrue($definition->hasOption('check'));
        self::assertTrue($definition->hasOption('rollback'));
        self::assertTrue($definition->hasOption('major'));
    }

    public function testRefusesToRunOutsideOfThePhar(): void
    {
        // The test suite runs from source, not from a phar, so self-update must
        // decline instead of trying to replace bin/wpc.
        $app = new Application();
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $output = new BufferedOutput();

        $exit = $app->run(new StringInput('self-update'), $output);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('only available from the packaged phar', $output->fetch());
    }

    /**
     * Crossing a major version is a decision: a pipeline running a scheduled
     * `self-update` must not wake up on 3.0 with its commands renamed.
     */
    public function testAMajorUpgradeIsRefusedUnlessAskedFor(): void
    {
        $phar = $this->makeTempDir() . '/wpc.phar';
        file_put_contents($phar, 'old');

        $output = new SplitOutput();
        $exit = $this->selfUpdate($phar, '1.1.2', ['2.0.0', hash('sha256', 'new')], 'self-update', $output);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('wpc self-update --major', $output->fetchError());
        self::assertSame('old', file_get_contents($phar));

        $output = new SplitOutput();
        $exit = $this->selfUpdate($phar, '1.1.2', ['2.0.0', hash('sha256', 'new'), 'new'], 'self-update --major', $output);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('updated to 2.0.0', $output->fetch());
        self::assertSame('new', file_get_contents($phar));
    }

    public function testCheckReportsAMajorUpgradeAsSuch(): void
    {
        $phar = $this->makeTempDir() . '/wpc.phar';
        file_put_contents($phar, 'old');

        $output = new SplitOutput();
        $exit = $this->selfUpdate($phar, '1.1.2', ['2.0.0', hash('sha256', 'new')], 'self-update --check --output=json', $output);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['update_available' => true, 'major' => true], json_decode($output->fetch(), true));
    }

    public function testANewerBuildIsNotReplaced(): void
    {
        $phar = $this->makeTempDir() . '/wpc.phar';
        file_put_contents($phar, 'beta');

        $output = new SplitOutput();
        $exit = $this->selfUpdate($phar, '2.0.0-beta', ['1.1.2'], 'self-update', $output);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('already up to date', $output->fetch());
        self::assertSame('beta', file_get_contents($phar));
    }

    /**
     * The download host's refusal is a sentence, in every format — not
     * Guzzle's message quoting the bucket's XML.
     */
    public function testAnUnpublishedReleaseIsReportedPlainly(): void
    {
        $phar = $this->makeTempDir() . '/wpc.phar';
        file_put_contents($phar, 'old');
        $refusal = new Response(403, [], '<?xml version="1.0"?><Error><Code>AccessDenied</Code></Error>');

        $output = new SplitOutput();
        $exit = $this->selfUpdate($phar, '@cli_version@', [$refusal, $refusal], 'self-update', $output);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No release is published at ' . SelfUpdate::SHA_URL . ' yet (HTTP 403).', $output->fetchError());
        self::assertStringNotContainsString('AccessDenied', $output->fetchError());

        $output = new SplitOutput();
        $this->selfUpdate($phar, '@cli_version@', [$refusal, $refusal], 'self-update --output=json', $output);

        self::assertSame(
            ['code' => Command::FAILURE, 'message' => 'No release is published at ' . SelfUpdate::SHA_URL . ' yet (HTTP 403).', 'errors' => []],
            json_decode($output->fetch(), true)
        );
    }

    /**
     * Runs self-update against $phar, the registry's answers queued in order:
     * a version string is the manifest, a Response is itself, any other string
     * a plain 200 body.
     *
     * @param list<string|Response> $answers
     */
    private function selfUpdate(string $phar, string $localVersion, array $answers, string $arguments, SplitOutput $output): int
    {
        $responses = [];
        foreach ($answers as $index => $answer) {
            $responses[] = match (true) {
                $answer instanceof Response => $answer,
                $index === 0 => new Response(200, [], (string) json_encode(['version' => $answer])),
                default => new Response(200, [], $answer),
            };
        }
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

        $runtime = new Runtime();
        $app = new Application(runtime: $runtime);
        $app->setAutoExit(false);
        $app->setCatchExceptions(true);
        $app->add(new SelfUpdate(
            new OutputRouter($runtime),
            static fn (): Updater => new Updater($phar, SelfUpdate::PHAR_URL, SelfUpdate::SHA_URL, $client, SelfUpdate::MANIFEST_URL, $localVersion),
        ));

        return $app->run(new StringInput($arguments), $output);
    }
}
