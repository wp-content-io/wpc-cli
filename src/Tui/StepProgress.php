<?php

namespace WpContent\Cli\Tui;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use WpContent\Cli\Builder\BuildProgress;

/**
 * The live step list shown while building and pushing on a terminal.
 *
 * Unlike {@see Screen} this reads no input — it only redraws a block as the
 * work advances, so `build --push` reads as a sequence of stages instead of a
 * bare progress bar with no context.
 */
final class StepProgress implements BuildProgress
{
    private const FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private const DONE = '✓';

    private const FAILED = '✗';

    private readonly ConsoleSectionOutput $section;

    /** @var list<array{label: string, detail: string, done: bool}> */
    private array $steps = [];

    /** Minimum delay between two redraws while a step is progressing. */
    private const REDRAW_INTERVAL_SECONDS = 0.08;

    private int $frame = 0;

    private bool $failed = false;

    private float $lastDraw = 0.0;

    public function __construct(ConsoleOutputInterface $output)
    {
        $this->section = $output->section();
    }

    public function step(string $label): void
    {
        $this->completeCurrent();
        $this->steps[] = ['label' => $label, 'detail' => '', 'done' => false];
        $this->draw();
    }

    public function detail(string $detail): void
    {
        $index = array_key_last($this->steps);
        if ($index === null) {
            return;
        }

        $this->steps[$index]['detail'] = $detail;

        // Throttled: a build with thousands of files would otherwise spend more
        // time repainting the step list than writing the archive.
        if (microtime(true) - $this->lastDraw >= self::REDRAW_INTERVAL_SECONDS) {
            $this->draw();
        }
    }

    public function file(string $path): void
    {
        // Only advances the spinner; drawing is driven by detail().
        $this->frame++;
    }

    public function finish(): void
    {
        $this->completeCurrent();
        $this->draw();
    }

    public function fail(string $message): void
    {
        $this->failed = true;
        $index = array_key_last($this->steps);
        if ($index !== null) {
            $this->steps[$index]['detail'] = $message;
        }
        $this->draw();
    }

    private function completeCurrent(): void
    {
        $index = array_key_last($this->steps);
        if ($index !== null) {
            $this->steps[$index]['done'] = true;
        }
    }

    private function draw(): void
    {
        $this->lastDraw = microtime(true);
        $lines = [];
        $lastIndex = array_key_last($this->steps);

        foreach ($this->steps as $index => $step) {
            $marker = match (true) {
                $step['done'] => '<info>' . self::DONE . '</info>',
                $index === $lastIndex && $this->failed => '<error>' . self::FAILED . '</error>',
                default => '<comment>' . self::FRAMES[$this->frame % count(self::FRAMES)] . '</comment>',
            };

            $lines[] = sprintf(
                ' %s %s%s',
                $marker,
                Text::escape($step['label']),
                $step['detail'] !== '' ? ' <fg=gray>' . Text::escape($step['detail']) . '</>' : ''
            );
        }

        $this->section->overwrite($lines);
    }
}
