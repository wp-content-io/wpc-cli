<?php

namespace WpContent\Cli;

use Symfony\Component\Console\Input\InputDefinition;

/**
 * Glues the words a shell hands us into the single token Symfony parses.
 *
 * The public grammar is `wpc plugin list`, so the shell passes two words. The
 * command is really *named* "plugin list" — Symfony only rejects a `::` in a
 * command name, a space is fine — which is what makes `wpc plugin list --help`
 * print "Usage: plugin list" with no custom descriptor. All that is missing is
 * putting the two words back together before the input is bound.
 *
 * Everything here is static and side-effect free so the grammar can be tested
 * without a terminal or an API.
 *
 * One case stays out of reach: `wpc plugin -m list push x.zip`, where a verb is
 * passed as an option *value* before the verb. Only the application's own
 * options are defined this early, and the per-command ones cannot be merged in
 * to make up for it — `-p` is `--push` on `build` and `--page` on `list`, so the
 * same token both takes a value and does not.
 */
final class CommandLine
{
    /**
     * @param list<string>            $argv      raw $_SERVER['argv'], binary included
     * @param callable(string): bool  $isCommand tells whether a name is registered
     *
     * @return list<string>
     */
    public static function normalize(array $argv, InputDefinition $definition, callable $isCommand): array
    {
        if ($argv === []) {
            return $argv;
        }

        $binary = array_shift($argv);
        $tokens = array_values($argv);
        $positions = self::argumentPositions($tokens, $definition);

        // `wpc help plugin list` asks about the pair that follows.
        $offset = isset($positions[0]) && $tokens[$positions[0]] === 'help' ? 1 : 0;

        $first = $positions[$offset] ?? null;
        if ($first === null) {
            return [$binary, ...$tokens];
        }

        $word = $tokens[$first];
        $isNoun = in_array($word, ResourceType::names(), true);
        $next = $positions[$offset + 1] ?? null;
        $verb = null;

        // The verb is the first following argument that completes a command
        // name we know. Anything in between is an option value we could not
        // recognise — only the *application*'s options are defined at this
        // point, so `--page 2` looks like an argument, and `wpc plugin --page 2
        // list` used to be read as the command "plugin 2".
        foreach (array_slice($positions, $offset + 1) as $position) {
            if ($isCommand($word . ' ' . $tokens[$position])) {
                $verb = $position;
                break;
            }
        }

        // An unknown verb is still merged when it directly follows the noun, so
        // the error reads `Command "plugin lst" is not defined` instead of
        // complaining about the namespace alone.
        if ($verb === null && $isNoun && $next === $first + 1) {
            $verb = $next;
        }

        if ($verb !== null) {
            $pair = $word . ' ' . $tokens[$verb];
            array_splice($tokens, $verb, 1);
            $tokens[$first] = $pair;

            return [$binary, ...$tokens];
        }

        if ($next !== null) {
            return [$binary, ...$tokens];
        }

        // A namespace on its own — `wpc plugin`, `wpc plugin --help`,
        // `wpc help plugin` — lists that namespace's verbs and exits 0.
        if ($isNoun) {
            $rest = [];
            foreach ($tokens as $index => $token) {
                if ($index === $first || ($offset === 1 && $index === $positions[0])) {
                    continue;
                }
                if ($token === '--help' || $token === '-h') {
                    continue;
                }
                $rest[] = $token;
            }

            return [$binary, 'list', $word, ...$rest];
        }

        return [$binary, ...$tokens];
    }

    /**
     * Same merge, applied to the word list `_complete` receives through its
     * repeated `-i` option (and the `-c` cursor index that goes with it).
     *
     * Only a *complete* pair is merged: while the cursor still sits on the verb
     * the two words must stay apart, otherwise there is nothing left to
     * complete. The words typed before the cursor are returned alongside, since
     * that is the only way {@see Application::complete()} can tell "completing
     * the namespace" from "completing the verb".
     *
     * @param list<string>           $argv
     * @param callable(string): bool $isCommand
     *
     * @return array{argv: list<string>, words: list<string>}
     */
    public static function normalizeCompletion(array $argv, callable $isCommand): array
    {
        $words = [];
        $current = null;

        foreach ($argv as $token) {
            if (str_starts_with($token, '-i')) {
                $words[] = substr($token, 2);
            } elseif (str_starts_with($token, '--input=')) {
                $words[] = substr($token, 8);
            } elseif (str_starts_with($token, '-c')) {
                $current = (int) substr($token, 2);
            } elseif (str_starts_with($token, '--current=')) {
                $current = (int) substr($token, 10);
            }
        }

        if ($current === null || $words === []) {
            return ['argv' => $argv, 'words' => []];
        }

        $typed = array_slice($words, 0, $current);

        // The cursor is past the verb: the pair is settled, glue it.
        if ($current >= 3 && isset($words[1], $words[2]) && $isCommand($words[1] . ' ' . $words[2])) {
            array_splice($words, 1, 2, [$words[1] . ' ' . $words[2]]);
            --$current;

            $argv = self::rebuildCompletion($argv, $words, $current);
        }

        return ['argv' => $argv, 'words' => $typed];
    }

    /**
     * @param list<string> $argv
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function rebuildCompletion(array $argv, array $words, int $current): array
    {
        $rebuilt = [];
        $written = false;

        foreach ($argv as $token) {
            if (str_starts_with($token, '-i') || str_starts_with($token, '--input=')) {
                if (!$written) {
                    foreach ($words as $word) {
                        $rebuilt[] = '-i' . $word;
                    }
                    $written = true;
                }

                continue;
            }

            if (str_starts_with($token, '-c') || str_starts_with($token, '--current=')) {
                $rebuilt[] = '-c' . $current;

                continue;
            }

            $rebuilt[] = $token;
        }

        return $rebuilt;
    }

    /**
     * Positions of the tokens that are arguments rather than options or option
     * values. Stops at `--`, after which nothing is a command name.
     *
     * All of them, not the first few: the verb may sit behind several options
     * this definition knows nothing about (`plugin --page 2 --per-page 5 list`),
     * and {@see normalize()} needs to reach it to recognise the pair.
     *
     * @param list<string> $tokens
     *
     * @return list<int>
     */
    private static function argumentPositions(array $tokens, InputDefinition $definition): array
    {
        $positions = [];

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if ($token === '--') {
                break;
            }

            if ($token !== '' && $token[0] === '-') {
                if (self::expectsValue($token, $definition)) {
                    $index++;
                }

                continue;
            }

            $positions[] = $index;
        }

        return $positions;
    }

    /**
     * Whether this option token swallows the next one as its value, so
     * `wpc --output json plugin list` is not mistaken for `json plugin`.
     */
    private static function expectsValue(string $token, InputDefinition $definition): bool
    {
        if (str_contains($token, '=')) {
            return false;
        }

        if (str_starts_with($token, '--')) {
            $name = substr($token, 2);

            return $name !== '' && $definition->hasOption($name) && $definition->getOption($name)->acceptValue();
        }

        // Only a bare "-x" can take the next token; "-xvalue" carries its own.
        if (strlen($token) !== 2) {
            return false;
        }

        return $definition->hasShortcut($token[1]) && $definition->getOptionForShortcut($token[1])->acceptValue();
    }
}
