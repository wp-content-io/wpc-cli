<?php

namespace WpContent\Cli\Tui;

use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Keyboard-driven questions for the `init` wizard.
 *
 * Unlike the console QuestionHelper these repaint a live block, so a list of
 * choices is walked with the arrows and ticked with space rather than answered
 * blind. Each answered prompt collapses to a single recap line, which keeps the
 * finished wizard readable as a transcript.
 */
final class Prompt
{
    private const CURSOR_MARKER = '▸ ';

    /**
     * The drawing surface, shared by every question.
     *
     * One per prompt, not one per question: a `Screen` claims a
     * `ConsoleSectionOutput`, and the wizard asks up to a dozen questions in a
     * row — that used to leave a dozen sections accumulating in the output's
     * shared list, each still holding the lines it had drawn.
     */
    private ?Screen $screen = null;

    /**
     * @param KeyReader|null $reader the input source, injectable so the prompts
     *                               can be driven without a terminal
     */
    public function __construct(
        private readonly ConsoleOutputInterface $output,
        private readonly ?KeyReader $reader = null,
    ) {
    }

    private function screen(): Screen
    {
        return $this->screen ??= new Screen($this->output, $this->reader);
    }

    /**
     * A free-text answer, pre-filled with $default.
     *
     * @throws Cancelled
     */
    public function text(string $label, string $default = ''): string
    {
        $value = $default;
        $screen = $this->screen();
        $cancelled = false;

        // Not an arrow function: `fn` captures by value, so the frame would
        // redraw the answer as it was before the first keystroke — which is how
        // the text being typed came to be invisible.
        $completed = $screen->run(
            function () use ($label, &$value): array {
                return [
                    sprintf('<comment>?</comment> <options=bold>%s</>', Text::escape($label)),
                    '  ' . Text::escape($value) . Theme::caret(),
                    '  <fg=gray>⏎ confirm · esc cancel</>',
                ];
            },
            function (KeyPress $key) use (&$value, &$cancelled): bool {
                if ($key->is(Key::Enter)) {
                    return false;
                }
                if ($key->is(Key::Escape)) {
                    $cancelled = true;

                    return false;
                }
                if ($key->is(Key::Backspace)) {
                    $value = mb_substr($value, 0, -1);
                } elseif ($key->isPrintable()) {
                    $value .= $key->char;
                }

                return true;
            }
        );

        $this->guardCancellation($completed, $cancelled);
        // The answer, not the default it started from: clearing the field and
        // confirming used to recap a value the wizard was not going to use.
        $this->recap($label, $value !== '' ? $value : '(empty)');

        return $value;
    }

    /**
     * @throws Cancelled
     */
    public function confirm(string $label, bool $default = false): bool
    {
        $value = $default;
        $screen = $this->screen();
        $cancelled = false;

        $completed = $screen->run(
            function () use ($label, &$value): array {
                return [
                    sprintf('<comment>?</comment> <options=bold>%s</>', Text::escape($label)),
                    sprintf(
                        '  %s   %s',
                        $value ? Theme::selected(' Yes ') : Theme::muted(' Yes '),
                        $value ? Theme::muted(' No ') : Theme::selected(' No ')
                    ),
                    '  <fg=gray>←→ choose · ⏎ confirm · esc cancel</>',
                ];
            },
            function (KeyPress $key) use (&$value, &$cancelled): bool {
                if ($key->is(Key::Enter)) {
                    return false;
                }
                if ($key->is(Key::Escape)) {
                    $cancelled = true;

                    return false;
                }
                if ($key->is(Key::Left, Key::Right, Key::Tab)) {
                    $value = !$value;
                } elseif ($key->isChar('y', 'Y')) {
                    $value = true;
                } elseif ($key->isChar('n', 'N')) {
                    $value = false;
                }

                return true;
            }
        );

        $this->guardCancellation($completed, $cancelled);
        $this->recap($label, $value ? 'yes' : 'no');

        return $value;
    }

    /**
     * Tick any number of choices. Returns the selected labels, in the order they
     * were offered.
     *
     * @param list<string> $choices
     * @param list<string> $preselected
     *
     * @return list<string>
     *
     * @throws Cancelled
     */
    public function multiselect(string $label, array $choices, array $preselected = []): array
    {
        $selected = [];
        foreach ($choices as $choice) {
            $selected[$choice] = in_array($choice, $preselected, true);
        }

        $cursor = 0;
        $cancelled = false;
        $screen = $this->screen();

        $completed = $screen->run(
            function () use ($label, $choices, &$selected, &$cursor, $screen): array {
                $lines = [sprintf('<comment>?</comment> <options=bold>%s</>', Text::escape($label))];

                // Keep the list inside the viewport, following the cursor.
                $height = max(1, $screen->height() - 3);
                $offset = max(0, min($cursor - intdiv($height, 2), max(0, count($choices) - $height)));

                foreach (array_slice($choices, $offset, $height, true) as $index => $choice) {
                    $line = sprintf(
                        '%s[%s] %s',
                        $index === $cursor ? self::CURSOR_MARKER : '  ',
                        $selected[$choice] ? '<info>x</info>' : ' ',
                        Text::escape($choice)
                    );
                    $lines[] = $index === $cursor ? "<options=bold>$line</>" : $line;
                }

                $lines[] = '  <fg=gray>↑↓ move · space toggle · a all · ⏎ confirm · esc cancel</>';

                return $lines;
            },
            function (KeyPress $key) use ($choices, &$selected, &$cursor, &$cancelled): bool {
                if ($key->is(Key::Escape)) {
                    $cancelled = true;

                    return false;
                }

                return match (true) {
                    $key->is(Key::Enter) => false,
                    $key->is(Key::Up) => $this->move($cursor, -1, count($choices)),
                    $key->is(Key::Down) => $this->move($cursor, 1, count($choices)),
                    $key->is(Key::Home) => $this->move($cursor, -count($choices), count($choices)),
                    $key->is(Key::End) => $this->move($cursor, count($choices), count($choices)),
                    $key->isChar(' ') => $this->toggle($selected, $choices[$cursor] ?? null),
                    $key->isChar('a') => $this->toggleAll($selected),
                    default => true,
                };
            }
        );

        $this->guardCancellation($completed, $cancelled);

        $chosen = array_values(array_filter($choices, static fn (string $choice): bool => $selected[$choice]));

        $this->recap($label, $chosen === [] ? 'none' : sprintf('%d selected', count($chosen)));

        return $chosen;
    }

    /**
     * A prompt ends three ways: answered, escaped, or interrupted (Ctrl+C, or
     * stdin closing under us). Only the first is an answer.
     *
     * @throws Cancelled
     */
    private function guardCancellation(bool $completed, bool $cancelled): void
    {
        if ($cancelled || !$completed) {
            throw new Cancelled();
        }
    }

    /** Leave a one-line trace of the answered prompt above the next one. */
    private function recap(string $label, string $answer): void
    {
        $this->output->writeln(sprintf(
            '<info>✓</info> %s <fg=gray>%s</>',
            Text::escape($label),
            Text::escape(Text::truncate(Text::singleLine($answer), 60))
        ));
    }

    private function move(int &$cursor, int $delta, int $count): bool
    {
        $cursor = max(0, min($cursor + $delta, max(0, $count - 1)));

        return true;
    }

    /**
     * @param array<string, bool> $selected
     */
    private function toggle(array &$selected, ?string $choice): bool
    {
        if ($choice !== null && array_key_exists($choice, $selected)) {
            $selected[$choice] = !$selected[$choice];
        }

        return true;
    }

    /**
     * @param array<string, bool> $selected
     */
    private function toggleAll(array &$selected): bool
    {
        $enable = in_array(false, $selected, true);
        foreach ($selected as $choice => $isSelected) {
            $selected[$choice] = $enable;
        }

        return true;
    }
}
