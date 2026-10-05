<?php

namespace WpContent\Cli\Tests\Command;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Application;
use WpContent\Cli\Tests\EnvGuardTrait;
use WpContent\Cli\Tests\Fake\FakeRegistryClient;
use WpContent\Cli\Tests\SplitOutput;
use WpContent\Cli\Tests\TempDirTrait;

/**
 * The two promises the v2 rename makes to people who are not watching:
 * a v1 pipeline keeps working and keeps printing the same thing on stdout, and
 * a command that now knows how to ask still fails the same way when there is
 * nobody to ask.
 */
final class GrammarMigrationTest extends TestCase
{
    use EnvGuardTrait;
    use TempDirTrait;

    private const OPT_OUT = 'WPC_NO_DEPRECATED_WARNING';

    protected function setUp(): void
    {
        // In all three sources: clearing it with putenv() alone left the notice
        // switched off on any machine that genuinely exports it.
        $this->setEnv(self::OPT_OUT, null);
        // The list commands need a key before they call anything.
        $this->setEnv('WPC_API_KEY', 'test-key');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    public function testALegacyNameKeepsItsStdoutAndWarnsOnStderr(): void
    {
        $file = $this->pluginFile();
        $output = new SplitOutput();

        $this->application()->run(new StringInput("plugin:manifest --get=Version $file"), $output);

        $stderr = $output->fetchError();

        self::assertSame('3.2.1', trim($output->fetch()), 'stdout must be exactly what v1 printed');
        self::assertStringContainsString('"plugin:manifest" is deprecated', $stderr);
        self::assertStringContainsString('wpc plugin manifest', $stderr, 'the notice must name the replacement');
    }

    public function testTheNoticeStaysOutOfMachineOutput(): void
    {
        $file = $this->pluginFile();

        $json = new SplitOutput();
        $this->application()->run(new StringInput("plugin:manifest --output=json $file"), $json);
        self::assertSame('', $json->fetchError(), '--output=json must stay silent on both streams');

        $quiet = new SplitOutput(OutputInterface::VERBOSITY_QUIET);
        $this->application()->run(new StringInput("plugin:manifest --get=Version $file"), $quiet);
        self::assertSame('', $quiet->fetchError(), '-q must silence the notice too');
    }

    public function testTheNoticeCanBeOptedOut(): void
    {
        $file = $this->pluginFile();
        $this->setEnv(self::OPT_OUT, '1');
        $output = new SplitOutput();

        $this->application()->run(new StringInput("plugin:manifest --get=Version $file"), $output);

        self::assertSame('', $output->fetchError());
    }

    public function testTheCurrentNameSaysNothing(): void
    {
        $file = $this->pluginFile();
        $output = new SplitOutput();

        $this->application()->run(new StringInput("'plugin manifest' --get=Version $file"), $output);

        self::assertSame('3.2.1', trim($output->fetch()));
        self::assertSame('', $output->fetchError());
    }

    /**
     * v1's `--paged`, in every spelling it took, still turns the page — and
     * says so on stderr only.
     */
    public function testTheLegacyPageOptionStillTurnsThePage(): void
    {
        foreach (['plugin:ls --paged=2', "'plugin list' --paged 2", 'plugin:ls -p 2'] as $arguments) {
            $registry = $this->listing('/plugins?page=2&per_page=36', 'plugins');
            $output = new SplitOutput();

            $exit = $this->application($registry)->run(new StringInput($arguments), $output);

            self::assertSame(Command::SUCCESS, $exit, $arguments);
            self::assertSame(['/plugins?page=2&per_page=36'], $registry->calls, $arguments);
            self::assertStringNotContainsString('deprecated', $output->fetch(), "$arguments: stdout must stay the answer");
        }

        $registry = $this->listing('/themes?page=3&per_page=36', 'themes');
        $output = new SplitOutput();
        $this->application($registry)->run(new StringInput("'theme list' --paged=3"), $output);

        self::assertSame(['/themes?page=3&per_page=36'], $registry->calls);
        self::assertStringContainsString('"--paged" is deprecated, use "--page"', $output->fetchError());
    }

    public function testTheLegacyPageOptionNoticeFollowsTheSameRules(): void
    {
        $json = new SplitOutput();
        $this->application($this->listing('/plugins?page=2&per_page=36', 'plugins'))
            ->run(new StringInput("'plugin list' --paged=2 --output=json"), $json);
        self::assertSame('', $json->fetchError(), '--output=json must stay silent on stderr');
        self::assertIsArray(json_decode($json->fetch(), true, flags: JSON_THROW_ON_ERROR));

        $quiet = new SplitOutput(OutputInterface::VERBOSITY_QUIET);
        $this->application($this->listing('/plugins?page=2&per_page=36', 'plugins'))
            ->run(new StringInput("'plugin list' --paged=2"), $quiet);
        self::assertSame('', $quiet->fetchError(), '-q must silence the notice too');

        $this->setEnv(self::OPT_OUT, '1');
        $optedOut = new SplitOutput();
        $this->application($this->listing('/plugins?page=2&per_page=36', 'plugins'))
            ->run(new StringInput("'plugin list' --paged=2"), $optedOut);
        self::assertSame('', $optedOut->fetchError());
    }

    /**
     * Accepted, never advertised: the help, the listing and the completion all
     * read the definition, and the old name is not in it.
     */
    public function testTheLegacyPageOptionIsNotAdvertised(): void
    {
        $application = $this->application();

        foreach (['plugin list', 'theme list'] as $name) {
            self::assertFalse($application->get($name)->getDefinition()->hasOption('paged'), $name);

            $help = new SplitOutput();
            $application->run(new StringInput("help '$name'"), $help);
            self::assertStringNotContainsString('paged', $help->fetch(), $name);
        }
    }

    /** Only the list commands ever had it; anywhere else it is still unknown. */
    public function testTheLegacyPageOptionIsOnlyKnownToTheListCommands(): void
    {
        $this->expectExceptionMessage('The "--paged" option does not exist.');

        $this->application(new FakeRegistryClient())->run(new StringInput("'plugin info' acme --paged=2"), new SplitOutput());
    }

    /**
     * A required argument is what gives a command its point: `info` without a
     * slug would just be `list` under another name. It fails, terminal or not.
     */
    public function testAMissingArgumentAlwaysFails(): void
    {
        foreach (['plugin info' => 'slug', 'plugin push' => 'artifact'] as $command => $argument) {
            try {
                $this->application()->run(new StringInput("'$command'"), new SplitOutput());
                self::fail("\"$command\" should refuse to run without its argument");
            } catch (RuntimeException $e) {
                self::assertSame("Not enough arguments (missing: \"$argument\").", $e->getMessage());
            }
        }
    }

    /**
     * Guards the decision above rather than its symptom: filling a required
     * argument from interact() would make the failure conditional on there
     * being no terminal, which is exactly what we do not want.
     *
     * `init` legitimately overrides it — its arguments are optional, and asking
     * is the whole command.
     */
    public function testNoCommandAnswersItsOwnRequiredArgument(): void
    {
        $application = new Application();

        foreach ($application->all() as $name => $command) {
            if ($command->getName() !== $name || !str_contains($name, ' ') || str_ends_with($name, ' init')) {
                continue;
            }

            $required = array_filter(
                $command->getDefinition()->getArguments(),
                static fn ($argument): bool => $argument->isRequired()
            );

            if ($required === []) {
                continue;
            }

            self::assertSame(
                Command::class,
                (new ReflectionMethod($command, 'interact'))->getDeclaringClass()->getName(),
                "\"$name\" has a required argument, so it must not prompt for it"
            );
        }
    }

    private function application(?FakeRegistryClient $registry = null): Application
    {
        $application = new Application($registry);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return $application;
    }

    private function listing(string $endpoint, string $collection): FakeRegistryClient
    {
        return (new FakeRegistryClient())->willReturn($endpoint, [
            $collection => [['name' => 'Acme', 'slug' => 'acme', 'version' => '1.0.0']],
            'info' => ['page' => 2, 'pages' => 3, 'results' => 3],
        ]);
    }

    private function pluginFile(): string
    {
        $dir = $this->makeTempDir();
        $file = "$dir/my-plugin.php";
        $this->writeFile($file, "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 3.2.1\n */\n");

        return $file;
    }
}
