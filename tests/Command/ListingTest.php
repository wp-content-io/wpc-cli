<?php

namespace WpContent\Cli\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use WpContent\Cli\Application;
use WpContent\Cli\Tests\Fake\FakeRegistryClient;
use WpContent\Cli\Tests\SplitOutput;

/**
 * The screen behind `wpc`, `wpc list` and `wpc plugin`.
 *
 * Symfony's own listing sorts every command into one alphabetical block and
 * spells the aliases out inline, which buries the two that matter under
 * `completion` and `help`.
 */
final class ListingTest extends TestCase
{
    public function testTheRootScreenLeadsWithWhatWpcManages(): void
    {
        $screen = $this->wpc('list');

        self::assertStringContainsString('Management commands', $screen);
        self::assertStringContainsString('plugin', $screen);
        self::assertStringContainsString('theme', $screen);
        self::assertStringContainsString('Global options', $screen);
        self::assertStringContainsString('--repository', $screen);
    }

    public function testTheRootScreenHidesTheVerbsAndTheAliases(): void
    {
        $screen = $this->wpc('list');

        // The verbs belong to their namespace's screen, not to this one.
        self::assertStringNotContainsString('plugin list', $screen);
        self::assertStringNotContainsString('plugin:ls', $screen);
    }

    public function testANamespaceScreenListsItsVerbsWithoutTheirNamespace(): void
    {
        $screen = $this->wpc('list plugin');

        self::assertStringContainsString('wpc plugin COMMAND', $screen);

        foreach (['init', 'list', 'info', 'build', 'push', 'manifest'] as $verb) {
            self::assertStringContainsString($verb, $screen, "\"$verb\" should be offered");
        }

        // Aliases are resolvable, not advertised.
        self::assertStringNotContainsString('plugin:ls', $screen);
    }

    /**
     * `--format`, `--raw` and `--short` are machine output, the same contract as
     * `--output=json`: they fall through to Symfony untouched.
     */
    public function testMachineReadableListingsAreLeftToSymfony(): void
    {
        $json = json_decode($this->wpc('list --format=json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($json);
        self::assertArrayHasKey('commands', $json);

        $names = array_column($json['commands'], 'name');
        self::assertContains('plugin list', $names);
    }

    /**
     * The same contract, per namespace — which the move from `plugin:build` to
     * `plugin build` had silently emptied: Symfony sorts namespaces by splitting
     * on `:`, so every canonical name fell into `_global` and only the legacy
     * aliases were left in `plugin`, where the descriptors do not print them.
     */
    public function testANamespaceIsStillListableByAMachine(): void
    {
        $raw = $this->wpc('list plugin --raw');
        $json = json_decode($this->wpc('list plugin --format=json'), true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($json['commands'], 'name');

        foreach (['build', 'info', 'init', 'list', 'manifest', 'push'] as $verb) {
            self::assertStringContainsString("plugin $verb", $raw, "\"plugin $verb\" should be listed");
            self::assertContains("plugin $verb", $names);
        }

        // Aliases resolve, they are not part of the list.
        self::assertStringNotContainsString('plugin:ls', $raw);
        self::assertNotContains('plugin:ls', $names);
        self::assertStringNotContainsString('theme ', $raw, 'another namespace has no business here');
    }

    /**
     * `--output=json` is answered with json here too.
     *
     * These three entry points wrote their screen straight to the output, so a
     * wrapper discovering the verbs with `wpc plugin --output=json | jq` got
     * `Usage:` and a paragraph of prose on the stream it was parsing — the very
     * regression the router was introduced to prevent.
     */
    public function testTheScreensAnswerJsonWithJson(): void
    {
        foreach (['', 'list', 'list plugin'] as $arguments) {
            $payload = json_decode($this->wpc(trim("$arguments --output=json")), true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($payload, "\"wpc $arguments\" must answer json with json");
            self::assertArrayHasKey('usage', $payload);
            self::assertArrayHasKey('commands', $payload);
            self::assertArrayHasKey('options', $payload);
            self::assertNotSame([], $payload['commands']);
        }
    }

    /**
     * Under json the commands are the names a caller can run, not the two nouns
     * the human screen groups them under — and each carries its aliases rather
     * than being listed once per name.
     */
    public function testTheJsonListingCarriesRunnableNamesAndTheirAliases(): void
    {
        $payload = json_decode($this->wpc('list --output=json'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($payload['commands'], 'name');

        self::assertContains('plugin list', $names);
        self::assertContains('self-update', $names);
        self::assertNotContains('plugin:ls', $names, 'an alias is not a command of its own');
        self::assertSame($names, array_unique($names));

        $byName = array_column($payload['commands'], null, 'name');
        self::assertContains('plugin:ls', $byName['plugin list']['aliases']);

        // The wpc options lead, and each appears exactly once — they are named
        // twice on purpose to be pulled to the front of the human screen.
        $options = array_column($payload['options'], 'name');
        self::assertSame('--output', $options[0]);
        self::assertSame($options, array_unique($options));
    }

    /**
     * `wpc plugin --output=json` answers for that namespace alone.
     */
    public function testANamespaceListingIsScopedUnderJson(): void
    {
        $payload = json_decode($this->wpc('list plugin --output=json'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($payload['commands'], 'name');

        self::assertSame([
            'plugin build', 'plugin info', 'plugin init',
            'plugin list', 'plugin manifest', 'plugin push',
        ], $names);
        self::assertStringContainsString('wpc plugin COMMAND', $payload['usage']);
    }

    private function wpc(string $arguments): string
    {
        $application = new Application(new FakeRegistryClient());
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $output = new SplitOutput();
        $exit = $application->run(new StringInput($arguments), $output);

        self::assertSame(Command::SUCCESS, $exit);

        return $output->fetch();
    }
}
