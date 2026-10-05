<?php

namespace WpContent\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use WpContent\Cli\Config;

final class ConfigTest extends TestCase
{
    use EnvGuardTrait;

    protected function setUp(): void
    {
        // Cleared everywhere, `getenv()` included: the maintainer's own checkout
        // exports WPC_REPO_URL through direnv, and unsetting the superglobals
        // alone left the default-value test reading it.
        foreach (['WPC_REPO_URL', 'WPC_API_KEY'] as $key) {
            $this->setEnv($key, null);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    public function testCommandLineOptionsTakePrecedence(): void
    {
        $_ENV['WPC_REPO_URL'] = 'https://env.example';
        $_ENV['WPC_API_KEY'] = 'env-key';

        $config = new Config($this->input([
            '--repository' => 'https://option.example',
            '--api-key' => 'option-key',
        ]));

        self::assertSame('https://option.example', $config->repository);
        self::assertSame('option-key', $config->apiKey);
    }

    public function testFallsBackToEnvironment(): void
    {
        $_ENV['WPC_REPO_URL'] = 'https://env.example';
        $_ENV['WPC_API_KEY'] = 'env-key';

        $config = new Config($this->input([]));

        self::assertSame('https://env.example', $config->repository);
        self::assertSame('env-key', $config->apiKey);
    }

    /**
     * `putenv()` is invisible to the superglobals, and the superglobals depend
     * on `variables_order`. Config read only the latter, so a wrapper script
     * exporting the key this way silently pushed to the public registry with no
     * credentials — while the very same process honoured CI and WPC_NO_TUI.
     */
    public function testReadsAVariableSetThroughPutenvAlone(): void
    {
        putenv('WPC_REPO_URL=https://from-putenv.example');
        putenv('WPC_API_KEY=putenv-key');

        $config = new Config($this->input([]));

        self::assertSame('https://from-putenv.example', $config->repository);
        self::assertSame('putenv-key', $config->apiKey);
    }

    public function testFallsBackToDefaults(): void
    {
        $config = new Config($this->input([]));

        self::assertSame('https://registry.wp-content.io', $config->repository);
        self::assertNull($config->apiKey);
        self::assertSame([], $config->validate());
    }

    public function testKnownOutputFormatsValidate(): void
    {
        foreach (Config::OUTPUT_FORMATS as $format) {
            $config = new Config($this->input(['--output' => $format]));

            self::assertSame($format, $config->outputFormat);
            self::assertSame([], $config->validate(), "$format should be a valid output format");
        }
    }

    public function testOnlyHumanMayTakeTheTerminalOver(): void
    {
        // `plain` used to still get the live progress bar and the wizard on a
        // terminal, because only display() looked at the format.
        self::assertTrue((new Config($this->input([])))->allowsInteractive());
        self::assertTrue((new Config($this->input(['--output' => 'human'])))->allowsInteractive());
        self::assertFalse((new Config($this->input(['--output' => 'plain'])))->allowsInteractive());
        self::assertFalse((new Config($this->input(['--output' => 'json'])))->allowsInteractive());
    }

    public function testUnknownOutputFormatIsRejected(): void
    {
        // It used to fall through to the human rendering, which silently handed
        // tables to a script that had asked for something else.
        $errors = (new Config($this->input(['--output' => 'yaml'])))->validate();

        self::assertCount(1, $errors);
        self::assertStringContainsString('yaml', $errors[0]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function input(array $params): InputInterface
    {
        $definition = new InputDefinition([
            new InputOption('repository', null, InputOption::VALUE_REQUIRED),
            new InputOption('api-key', null, InputOption::VALUE_REQUIRED),
            new InputOption('output', null, InputOption::VALUE_REQUIRED, '', 'human'),
        ]);

        return new ArrayInput($params, $definition);
    }
}
