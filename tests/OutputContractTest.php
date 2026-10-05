<?php

namespace WpContent\Cli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use WpContent\Cli\Api\ApiError;
use WpContent\Cli\Application;
use WpContent\Cli\Config;
use WpContent\Cli\Tests\Fake\FakeRegistryClient;

/**
 * Which stream carries what, in which format, with which exit code.
 *
 * This is the CLI's side of the bargain with whoever automates it, and it used
 * to hold only as long as nothing went wrong: errors were written to stdout in
 * every format, and a mistyped command under `--output=json` answered with
 * Symfony's boxed message.
 */
final class OutputContractTest extends TestCase
{
    use EnvGuardTrait;
    use TempDirTrait;

    /**
     * The registry commands need a key before they call anything; this suite
     * is about what they do once they have one.
     */
    protected function setUp(): void
    {
        $this->setEnv('WPC_API_KEY', 'test-key');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    /**
     * stdout is the answer. On failure there is no answer, so it stays empty —
     * a redirect must never collect a diagnostic where it expected a result.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function humanFailures(): iterable
    {
        yield 'unknown output format' => ["'plugin list' --output=nope", Command::INVALID];
        yield 'api error' => ["'plugin list'", Command::FAILURE];
        yield 'missing manifest file' => ["'plugin manifest' /nope/nope.php", Command::INVALID];
        yield 'invalid pagination' => ["'plugin list' --per-page=abc", Command::INVALID];
        yield 'source directory missing' => ["'plugin build' /nope/nope", Command::INVALID];
        yield 'unknown command' => ["'plugin lst'", Command::INVALID];
        yield 'missing argument' => ["'plugin info'", Command::INVALID];
        yield 'wizard declined' => ["'plugin init' --no-interaction", Command::INVALID];
    }

    #[DataProvider('humanFailures')]
    public function testAHumanFailureLeavesStdoutEmpty(string $arguments, int $expectedExit): void
    {
        $output = new SplitOutput();

        $exit = $this->application()->run(new StringInput($arguments), $output);

        self::assertSame($expectedExit, $exit, "\"$arguments\" exit code");
        self::assertSame('', $output->fetch(), "\"$arguments\" must write nothing to stdout");
        self::assertNotSame('', $output->fetchError(), "\"$arguments\" must say why on stderr");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonFailures(): iterable
    {
        yield 'unknown command' => ["'plugin lst'"];
        yield 'missing argument' => ["'plugin info'"];
        yield 'api error' => ["'plugin list'"];
        yield 'missing manifest file' => ["'plugin manifest' /nope/nope.php"];
        yield 'invalid pagination' => ["'plugin list' --per-page=abc"];
    }

    /**
     * Under `--output=json` the failure *is* the answer: one object, on stdout,
     * with the shape every other error uses. The exit code tells it apart from
     * a success — the payload alone never has to.
     */
    #[DataProvider('jsonFailures')]
    public function testAJsonFailureIsStillJsonOnStdout(string $arguments): void
    {
        $output = new SplitOutput();

        $exit = $this->application()->run(new StringInput("$arguments --output=json"), $output);

        self::assertNotSame(Command::SUCCESS, $exit, "\"$arguments\" should not report success");

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertArrayHasKey('code', $payload);
        self::assertArrayHasKey('message', $payload);
        self::assertArrayHasKey('errors', $payload);
        self::assertNotSame('', $payload['message'], 'a failure has to say something');
        self::assertSame('', $output->fetchError(), 'nothing else may be written while json is the contract');
    }

    public function testASuccessfulJsonRunWritesOnlyItsPayload(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/plugins?page=1&per_page=36', [
            'plugins' => [['name' => 'Acme', 'slug' => 'acme']],
            'info' => ['page' => 1, 'pages' => 1, 'results' => 1],
        ]);

        $output = new SplitOutput();
        $exit = $this->application($registry)->run(new StringInput("'plugin list' --output=json"), $output);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('', $output->fetchError());

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('acme', $payload[0]['slug']);
    }

    /**
     * `-q` silences the diagnostics, not the answer.
     *
     * The payload was written at the default verbosity, so a CI step adding
     * `-q` to keep its log clean got an empty stream where the contract
     * promised an object — on success and on failure alike.
     */
    public function testQuietStillWritesTheJsonPayload(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/plugins?page=1&per_page=36', [
            'plugins' => [['name' => 'Acme', 'slug' => 'acme']],
            'info' => ['page' => 1, 'pages' => 1, 'results' => 1],
        ]);

        foreach ([$registry, new FakeRegistryClient()] as $client) {
            $output = new SplitOutput();
            $this->application($client)->run(new StringInput("'plugin list' --output=json -q"), $output);

            $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload, 'the payload is the answer, quiet or not');
        }
    }

    /**
     * The same cause has to produce the same code wherever it happens: an
     * unreachable registry is a failure (1), and only a command called wrongly
     * is invalid (2). `push` used to report an API error as invalid usage.
     */
    public function testTheSameCauseGivesTheSameExitCodeEverywhere(): void
    {
        $artifact = $this->makeTempDir() . '/acme.zip';
        $this->writeFile($artifact, 'not really a zip');

        foreach (["'plugin list'", "'plugin info' acme", "'plugin push' $artifact"] as $arguments) {
            $registry = new FakeRegistryClient();
            foreach (['/plugins?page=1&per_page=36', '/plugins/acme', '/plugins'] as $endpoint) {
                $registry->willFail($endpoint, ApiError::unreachable('http://registry.test'));
            }

            $exit = $this->application($registry)->run(new StringInput($arguments), new SplitOutput());

            self::assertSame(Command::FAILURE, $exit, "\"$arguments\" should fail, not report invalid usage");
        }
    }

    /**
     * A mistyped verb is reported once.
     *
     * The suggestion-carrying exception used to be chained to the one Symfony
     * threw, and Symfony renders the whole chain — so every typo printed "is
     * not defined" twice, with two different lists of alternatives.
     */
    public function testAMistypedVerbIsReportedOnce(): void
    {
        $output = new SplitOutput();

        $this->application()->run(new StringInput("'plugin lst'"), $output);

        $stderr = $output->fetchError();

        self::assertSame(1, substr_count($stderr, 'is not defined'));
        self::assertStringContainsString('plugin list', $stderr, 'the near-miss has to be offered');
    }

    /**
     * A mistyped command name is a failure, never a question.
     *
     * Symfony's `doRun()` reads the alternatives off the exception and, on
     * exactly one, stops ahead of `renderThrowable()` to ask "Do you want to
     * run X instead?" — prose on stdout, an exit status of 1, and no JSON at
     * all. `plugin lst` never hit it because six verbs look alike; `lst` and
     * `slef-update` have a single near-miss each, which is every CI job that
     * typos a top-level command.
     */
    public function testAMistypedCommandNameIsNeverTurnedIntoAQuestion(): void
    {
        foreach (['lst' => 'list', 'slef-update' => 'self-update'] as $typo => $meant) {
            $output = new SplitOutput();

            $exit = $this->application()->run(new StringInput("$typo --output=json"), $output);
            $stdout = $output->fetch();
            $stderr = $output->fetchError();

            self::assertSame(Command::INVALID, $exit, "\"$typo\" is a usage error");
            self::assertStringNotContainsString('Do you want to run', $stdout . $stderr);

            $payload = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($payload, "\"$typo\" must answer json with json");
            self::assertStringContainsString('is not defined', (string) $payload['message']);
            self::assertStringContainsString($meant, (string) $payload['message'], 'the near-miss is worth keeping');
        }
    }

    /**
     * Every usage error is rendered once, not just the mistyped ones.
     *
     * `doRunCommand()` rethrows what the console raises with the original
     * chained to it, and Symfony's renderer walks the whole chain — so a
     * missing argument printed its box twice.
     */
    public function testAUsageErrorIsRenderedOnce(): void
    {
        $errors = [
            "'plugin info'" => 'Not enough arguments',
            "'plugin list' --nope" => 'does not exist',
        ];

        foreach ($errors as $arguments => $message) {
            $output = new SplitOutput();

            $exit = $this->application()->run(new StringInput($arguments), $output);

            self::assertSame(Command::INVALID, $exit, "\"$arguments\" exit code");
            self::assertSame(1, substr_count($output->fetchError(), $message), "\"$arguments\" says it once");
        }
    }

    /**
     * `--version` answers without a configuration.
     *
     * It is handled by the parent before any command is looked up, so it used
     * to be answered before the configuration was even read. Validating in
     * `doRun()` put a repository check in front of it, and an installer or a CI
     * preflight step checking the binary with `wpc --version` — before anything
     * is configured — started failing. What the invocation itself got wrong
     * still counts, though: a malformed option is a usage error anywhere.
     */
    public function testTheVersionIsAnsweredWithoutARepository(): void
    {
        $this->withEnv('WPC_REPO_URL', '', function (): void {
            $output = new SplitOutput();
            $exit = $this->application()->run(new StringInput('--version'), $output);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertStringContainsString('wp-content.io CLI', $output->fetch());

            $output = new SplitOutput();
            $exit = $this->application()->run(new StringInput('--version --output=bogus'), $output);

            self::assertSame(Command::INVALID, $exit, 'a malformed option is still a usage error');
        });
    }

    /**
     * Tags are `vX.Y.Z` from 2.1.0 on and Box stamps them verbatim; the
     * version is still shown the way every earlier release spelled it.
     */
    public function testAVPrefixedVersionIsShownWithoutIt(): void
    {
        foreach (['v2.1.0' => '2.1.0', 'V2.1.0' => '2.1.0', 'v2.1.0-3-gabc1234' => '2.1.0-3-gabc1234', '2.1.0' => '2.1.0'] as $stamped => $shown) {
            $application = new Application(new FakeRegistryClient(), version: $stamped);
            $application->setAutoExit(false);

            $output = new SplitOutput();
            $application->run(new StringInput('--version'), $output);

            self::assertSame("wp-content.io CLI $shown", trim(strip_tags($output->fetch())), $stamped);
        }
    }

    /**
     * A command that needs the registry as an organization fails before it
     * calls anything when there is no key: the registry used to be asked
     * anyway, and answered "Organization not found". `build --push` fails
     * before building, not after.
     */
    public function testARegistryCommandWithoutAKeyFailsBeforeAnyCall(): void
    {
        $artifact = $this->makeTempDir() . '/acme.zip';
        $this->writeFile($artifact, 'not really a zip');
        $source = $this->makeTempDir();
        $this->writeFile("$source/acme.php", "<?php\n/**\n * Plugin Name: Acme\n */\n");
        $outputDir = $this->makeTempDir();

        $this->withEnv('WPC_API_KEY', null, function () use ($artifact, $source, $outputDir): void {
            foreach ([
                "'plugin list'",
                "'theme info' acme",
                "'plugin push' $artifact",
                "'plugin build' $source --push --output-dir=$outputDir",
                "'plugin list' --api-key=",
            ] as $arguments) {
                $registry = new FakeRegistryClient();
                $output = new SplitOutput();

                $exit = $this->application($registry)->run(new StringInput($arguments), $output);

                self::assertSame(Command::INVALID, $exit, "\"$arguments\" exit code");
                self::assertSame('', $output->fetch(), "\"$arguments\" must write nothing to stdout");
                self::assertStringContainsString(Config::MISSING_API_KEY, $output->fetchError());
                self::assertSame([], $registry->calls, "\"$arguments\" must not reach the registry");
                self::assertSame([], $registry->uploads, "\"$arguments\" must not upload");
            }

            self::assertSame([], glob("$outputDir/*"), 'build --push must not build without a key');

            $output = new SplitOutput();
            $exit = $this->application()->run(new StringInput("'plugin list' --output=json"), $output);

            self::assertSame(Command::INVALID, $exit);
            self::assertSame(
                ['code' => Command::INVALID, 'message' => Config::MISSING_API_KEY, 'errors' => []],
                json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR)
            );
        });
    }

    /**
     * Only the commands that cannot work without a key ask for one: `init`
     * looks the author up on a best-effort basis, and `build` and `manifest`
     * never talk to the registry at all.
     */
    public function testTheLocalCommandsRunWithoutAKey(): void
    {
        $source = $this->makeTempDir();
        $this->writeFile("$source/acme.php", "<?php\n/**\n * Plugin Name: Acme\n * Version: 1.0.0\n */\n");
        $outputDir = $this->makeTempDir();
        $workdir = $this->makeTempDir();

        $this->withEnv('WPC_API_KEY', null, function () use ($source, $outputDir, $workdir): void {
            foreach ([
                "'plugin build' $source --output-dir=$outputDir",
                "'plugin manifest' $source/acme.php",
                "'plugin init' Acme -n",
            ] as $arguments) {
                $before = (string) getcwd();
                chdir($workdir);

                try {
                    $exit = $this->application()->run(new StringInput("$arguments --output=json"), new SplitOutput());
                } finally {
                    chdir($before);
                }

                self::assertSame(Command::SUCCESS, $exit, "\"$arguments\" needs no key");
            }
        });
    }

    public function testAnUnreachableRegistryNamesTheAddress(): void
    {
        $registry = (new FakeRegistryClient())
            ->willFail('/plugins/acme', ApiError::unreachable('http://registry.test'));

        $output = new SplitOutput();
        $this->application($registry)->run(new StringInput("'plugin info' acme"), $output);

        $stderr = $output->fetchError();

        // "Internal Server Error" was what a typo in --repository used to read
        // as, which sends the user looking at a server that is perfectly fine.
        self::assertStringContainsString('http://registry.test', $stderr);
        self::assertStringNotContainsString('Internal Server Error', $stderr);
    }

    private function application(?FakeRegistryClient $registry = null): Application
    {
        $application = new Application($registry ?? new FakeRegistryClient());
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        return $application;
    }
}
