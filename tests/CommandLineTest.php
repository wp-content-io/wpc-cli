<?php

namespace WpContent\Cli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpContent\Cli\Application;
use WpContent\Cli\CommandLine;

/**
 * The grammar itself: how the words a shell passes become a command name.
 *
 * Driven against the real command list, so a rename that breaks the merge shows
 * up here rather than in a terminal.
 */
final class CommandLineTest extends TestCase
{
    /**
     * @param list<string> $argv
     * @param list<string> $expected
     */
    #[DataProvider('argvCases')]
    public function testNormalize(array $argv, array $expected): void
    {
        $application = new Application();

        self::assertSame(
            $expected,
            CommandLine::normalize($argv, $application->getDefinition(), $application->has(...))
        );
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function argvCases(): iterable
    {
        yield 'the two words become one command name' => [
            ['wpc', 'plugin', 'list'],
            ['wpc', 'plugin list'],
        ];

        yield 'a space alias is a command too' => [
            ['wpc', 'plugin', 'ls'],
            ['wpc', 'plugin ls'],
        ];

        yield 'the v1 name is left exactly as typed' => [
            ['wpc', 'plugin:ls', '--output=json'],
            ['wpc', 'plugin:ls', '--output=json'],
        ];

        yield 'options after the verb are untouched' => [
            ['wpc', 'plugin', 'push', 'dist/acme.zip', '-m', 'notes'],
            ['wpc', 'plugin push', 'dist/acme.zip', '-m', 'notes'],
        ];

        yield 'options before the command are untouched' => [
            ['wpc', '--output=json', 'plugin', 'list'],
            ['wpc', '--output=json', 'plugin list'],
        ];

        yield 'a detached option value is not mistaken for the namespace' => [
            ['wpc', '--output', 'json', 'plugin', 'list'],
            ['wpc', '--output', 'json', 'plugin list'],
        ];

        yield 'the pair may be split by an option' => [
            ['wpc', 'plugin', '--output=json', 'list'],
            ['wpc', 'plugin list', '--output=json'],
        ];

        // Only the application's options are known this early, so the value of
        // a command option looked like an argument — and `plugin 2` was taken
        // for the command name, dropping the verb the user had typed.
        yield 'a command option and its value are stepped over' => [
            ['wpc', 'plugin', '--page', '2', 'list'],
            ['wpc', 'plugin list', '--page', '2'],
        ];

        yield 'several of them, too' => [
            ['wpc', 'plugin', '--page', '2', '--per-page', '5', 'list'],
            ['wpc', 'plugin list', '--page', '2', '--per-page', '5'],
        ];

        yield 'a short one as well' => [
            ['wpc', 'plugin', '-c', '5', 'list'],
            ['wpc', 'plugin list', '-c', '5'],
        ];

        // The verb wins over anything that merely looks like one: the scan
        // stops at the first pair that is a real command name.
        yield 'a directory named after a verb is not the verb' => [
            ['wpc', 'plugin', 'build', 'list'],
            ['wpc', 'plugin build', 'list'],
        ];

        yield 'help asks about the pair that follows' => [
            ['wpc', 'help', 'plugin', 'list'],
            ['wpc', 'help', 'plugin list'],
        ];

        yield 'a namespace on its own lists its verbs' => [
            ['wpc', 'plugin'],
            ['wpc', 'list', 'plugin'],
        ];

        yield 'so does asking for its help' => [
            ['wpc', 'plugin', '--help'],
            ['wpc', 'list', 'plugin'],
        ];

        yield 'and so does help with no verb' => [
            ['wpc', 'help', 'theme'],
            ['wpc', 'list', 'theme'],
        ];

        yield 'an unknown verb is still merged, for the error message' => [
            ['wpc', 'plugin', 'lst'],
            ['wpc', 'plugin lst'],
        ];

        yield 'a standalone command is left alone' => [
            ['wpc', 'self-update', '--check'],
            ['wpc', 'self-update', '--check'],
        ];

        yield 'list takes a namespace argument, it is not a pair' => [
            ['wpc', 'list', 'plugin'],
            ['wpc', 'list', 'plugin'],
        ];

        yield 'nothing after -- is a command name' => [
            ['wpc', '--', 'plugin', 'list'],
            ['wpc', '--', 'plugin', 'list'],
        ];

        yield 'but the pair before -- still merges' => [
            ['wpc', 'plugin', 'manifest', '--', '--odd-name.php'],
            ['wpc', 'plugin manifest', '--', '--odd-name.php'],
        ];

        yield 'the bare binary' => [
            ['wpc'],
            ['wpc'],
        ];

        yield 'an empty argv' => [
            [],
            [],
        ];
    }

    public function testCompletionMergesThePairOnceTheCursorIsPastIt(): void
    {
        $application = new Application();

        $result = CommandLine::normalizeCompletion(
            ['wpc', '_complete', '-sbash', '-c3', '-a1', '-iwpc', '-iplugin', '-ilist'],
            $application->has(...)
        );

        self::assertSame(
            ['wpc', '_complete', '-sbash', '-c2', '-a1', '-iwpc', '-iplugin list'],
            $result['argv'],
            'the cursor index must shift with the words it counts'
        );
        self::assertSame(['wpc', 'plugin', 'list'], $result['words']);
    }

    public function testCompletionKeepsTheWordsApartWhileTheVerbIsBeingTyped(): void
    {
        $application = new Application();

        // Merging here would leave nothing to complete: the verb is the answer.
        $argv = ['wpc', '_complete', '-sbash', '-c2', '-iwpc', '-iplugin', '-ili'];
        $result = CommandLine::normalizeCompletion($argv, $application->has(...));

        self::assertSame($argv, $result['argv']);
        self::assertSame(['wpc', 'plugin'], $result['words']);
    }

    public function testCompletionReportsTheWordsTypedBeforeTheCursor(): void
    {
        $application = new Application();

        // `wpc <TAB>`: bash drops the empty word but still counts it.
        $result = CommandLine::normalizeCompletion(
            ['wpc', '_complete', '-sbash', '-c1', '-iwpc'],
            $application->has(...)
        );

        self::assertSame(['wpc'], $result['words']);
    }
}
