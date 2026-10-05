<?php

namespace WpContent\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use WpContent\Cli\Application;
use WpContent\Cli\Tests\Fake\FakeRegistryClient;

/**
 * Pins the public CLI contract: every `plugin`/`theme` command name, its
 * aliases, description, arguments and options (with shortcuts and defaults),
 * plus the three global options. This snapshot is the safety net that keeps the
 * documented behaviour from drifting.
 */
final class CommandContractTest extends TestCase
{
    use EnvGuardTrait;

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
     * The v1 grammar, spelled out rather than derived: these names live in
     * customers' CI pipelines, which upgrade themselves through `self-update`.
     * Dropping one is a silent breakage on someone else's machine, so this list
     * is append-only — never regenerate it from the application.
     */
    private const LEGACY_NAMES = [
        'plugin:ls', 'plugin:info', 'plugin:start', 'plugin:build', 'plugin:push', 'plugin:manifest',
        'theme:ls', 'theme:info', 'theme:start', 'theme:build', 'theme:push', 'theme:manifest',
    ];

    /**
     * v1 options that were renamed, `command => [old => new]`. Same rule as the
     * names: append-only. They are accepted but kept out of the definition, so
     * the fixture below never sees them — this is where they are pinned.
     */
    private const LEGACY_OPTIONS = [
        'plugin list' => ['paged' => 'page'],
        'theme list' => ['paged' => 'page'],
    ];

    public function testEveryLegacyOptionStillResolves(): void
    {
        foreach (self::LEGACY_OPTIONS as $name => $renamed) {
            foreach ($renamed as $old => $new) {
                $collection = strtok($name, ' ') . 's';
                $endpoint = "/$collection?page=7&per_page=36";
                $registry = (new FakeRegistryClient())->willReturn($endpoint, [$collection => [], 'info' => ['page' => 7, 'pages' => 7, 'results' => 0]]);

                $application = new Application($registry);
                $application->setAutoExit(false);
                $application->setCatchExceptions(false);
                $application->run(new StringInput("'$name' --$old=7 --output=json"), new BufferedOutput());

                self::assertTrue($application->get($name)->getDefinition()->hasOption($new), "--$new is gone from \"$name\"");
                self::assertSame([$endpoint], $registry->calls, "v1 option --$old no longer reaches --$new on \"$name\"");
            }
        }
    }

    public function testEveryLegacyNameStillResolves(): void
    {
        $application = new Application();

        foreach (self::LEGACY_NAMES as $name) {
            self::assertTrue($application->has($name), "v1 name \"$name\" no longer resolves");
        }
    }

    public function testLegacyNamesResolveToTheSameCommandAsTheNewGrammar(): void
    {
        $application = new Application();

        $equivalents = [
            'plugin:ls' => 'plugin list',
            'plugin:start' => 'plugin init',
            'theme:ls' => 'theme list',
            'theme:start' => 'theme init',
            'plugin:push' => 'plugin push',
        ];

        foreach ($equivalents as $legacy => $current) {
            self::assertSame(
                $application->get($current),
                $application->get($legacy),
                "\"$legacy\" should be the very same command as \"$current\""
            );
        }
    }

    public function testResourceCommandsMatchFixture(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/command-contract.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        self::assertSame($fixture, $this->dumpResourceCommands());
    }

    public function testGlobalOptionsAreStable(): void
    {
        $definition = (new Application())->getDefinition();

        $expected = [
            'output' => ['human', 'Output format (human, plain, json)'],
            'repository' => [null, 'The repository api URL'],
            'api-key' => [null, 'API Key to use for request'],
        ];

        foreach ($expected as $name => [$default, $description]) {
            self::assertTrue($definition->hasOption($name), "Missing global option --{$name}");
            $option = $definition->getOption($name);
            self::assertNull($option->getShortcut(), "--{$name} should have no shortcut");
            self::assertTrue($option->acceptValue(), "--{$name} should accept a value");
            self::assertTrue($option->isValueRequired(), "--{$name} value should be required");
            self::assertFalse($option->isArray(), "--{$name} should not be an array");
            self::assertSame($default, $option->getDefault(), "--{$name} default changed");
            self::assertSame($description, $option->getDescription(), "--{$name} description changed");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dumpResourceCommands(): array
    {
        $cwd = getcwd();
        $normalize = static fn ($value) => is_string($value) ? str_replace($cwd, '@CWD@', $value) : $value;

        $commands = [];
        foreach ((new Application())->all() as $command) {
            if (!preg_match('/^(plugin|theme) /', (string) $command->getName())) {
                continue;
            }

            $definition = $command->getDefinition();

            $arguments = [];
            foreach ($definition->getArguments() as $argument) {
                $arguments[$argument->getName()] = [
                    'required' => $argument->isRequired(),
                    'isArray' => $argument->isArray(),
                    'default' => $normalize($argument->getDefault()),
                    'description' => $argument->getDescription(),
                ];
            }

            $options = [];
            foreach ($definition->getOptions() as $option) {
                $options[$option->getName()] = [
                    'shortcut' => $option->getShortcut(),
                    'acceptValue' => $option->acceptValue(),
                    'valueRequired' => $option->isValueRequired(),
                    'isArray' => $option->isArray(),
                    'default' => $normalize($option->getDefault()),
                    'description' => $option->getDescription(),
                ];
            }
            ksort($options);

            $commands[$command->getName()] = [
                'name' => $command->getName(),
                'description' => $command->getDescription(),
                'aliases' => $command->getAliases(),
                'arguments' => $arguments,
                'options' => $options,
            ];
        }
        ksort($commands);

        return $commands;
    }
}
