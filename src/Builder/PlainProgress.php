<?php

namespace WpContent\Cli\Builder;

use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Tui\Text;

/**
 * The non-interactive reporting: what CI, a pipe and `--output=plain` get.
 *
 * It is deliberately quieter than the TUI, but not silent — v1 drew a progress
 * bar for every `human` run, TTY or not, and dropping it left a `push` of a
 * large artifact indistinguishable from a hung process, with nothing to keep an
 * output-inactivity watchdog awake. Steps are announced, details are throttled,
 * and the per-file list stays behind `-v` as it always has.
 *
 * Everything here goes to the error stream (see `AbstractResourceCommand`), so
 * none of it can land in a redirect that was expecting the answer.
 */
final class PlainProgress implements BuildProgress
{
    /**
     * Long enough that a build packing thousands of files does not narrate each
     * one, short enough that a slow upload keeps producing output.
     */
    private const DETAIL_INTERVAL_SECONDS = 1.0;

    private string $lastDetail = '';

    private string $pendingDetail = '';

    private float $detailWrittenAt = 0.0;

    public function __construct(private readonly OutputInterface $output)
    {
    }

    public function step(string $label): void
    {
        // The step that is ending gets its last detail out first — that is the
        // one carrying the final percentage, or the archive's size.
        $this->flushDetail();
        $this->lastDetail = '';
        $this->pendingDetail = '';

        $this->output->writeln('<comment>' . Text::escape($label) . '</comment>');
    }

    public function detail(string $detail): void
    {
        if ($detail === '' || $detail === $this->lastDetail) {
            return;
        }

        $this->pendingDetail = $detail;

        if ($this->detailWrittenAt > 0.0
            && hrtime(true) / 1e9 - $this->detailWrittenAt < self::DETAIL_INTERVAL_SECONDS
        ) {
            return;
        }

        $this->flushDetail();
    }

    public function file(string $path): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln('+ ' . Text::escape($path));
        }
    }

    public function finish(): void
    {
        $this->flushDetail();
    }

    /**
     * Nothing: the failure itself is about to be rendered by the command, and
     * a half-written detail line under it would only compete with the message.
     */
    public function fail(string $message): void
    {
    }

    private function flushDetail(): void
    {
        if ($this->pendingDetail === '' || $this->pendingDetail === $this->lastDetail) {
            return;
        }

        $this->lastDetail = $this->pendingDetail;
        $this->detailWrittenAt = hrtime(true) / 1e9;

        $this->output->writeln('  ' . Text::escape($this->pendingDetail));
    }
}
