<?php

namespace WpContent\Cli\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Builder\PlainProgress;

/**
 * What CI, a pipe and `--output=plain` are told while something is happening.
 *
 * v1 drew a progress bar for every `human` run, TTY or not. The rework replaced
 * it with a reporter whose `detail()` was an empty method and whose `step()`
 * only spoke under `-v`, so a `push` of a large artifact printed nothing at all
 * for its whole duration: indistinguishable from a hung process, and with
 * nothing to keep an output-inactivity watchdog awake.
 */
final class PlainProgressTest extends TestCase
{
    public function testAnUploadSaysWhereItIsUpTo(): void
    {
        $output = new BufferedOutput();
        $progress = new PlainProgress($output);

        $progress->step('Uploading acme.zip');
        $progress->detail('50%');
        $progress->step('Published');
        $progress->finish();

        $written = $output->fetch();

        self::assertStringContainsString('Uploading acme.zip', $written);
        self::assertStringContainsString('50%', $written);
        self::assertStringContainsString('Published', $written);
    }

    /**
     * The last detail of a step is the one worth having — the final percentage,
     * or the archive's size — so it is flushed rather than thrown away by the
     * throttle that keeps the log readable.
     */
    public function testTheLastDetailIsNotLostToTheThrottle(): void
    {
        $output = new BufferedOutput();
        $progress = new PlainProgress($output);

        $progress->step('Packing archive');
        foreach (['1 file', '2 files', '3 files'] as $detail) {
            $progress->detail($detail);
        }
        $progress->detail('acme.zip · 12 KB');
        $progress->finish();

        self::assertStringContainsString('acme.zip · 12 KB', $output->fetch());
    }

    /**
     * `-q` silences the diagnostics, and progress is a diagnostic.
     */
    public function testQuietSaysNothing(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);
        $progress = new PlainProgress($output);

        $progress->step('Uploading acme.zip');
        $progress->detail('50%');
        $progress->finish();

        self::assertSame('', $output->fetch());
    }

    /**
     * The per-file list stays where it has always been: behind `-v`. It is the
     * one thing here that grows with the size of the plugin.
     */
    public function testTheFileListStaysBehindVerbose(): void
    {
        $output = new BufferedOutput();
        (new PlainProgress($output))->file('/src/acme/acme.php');

        self::assertSame('', $output->fetch());

        $verbose = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        (new PlainProgress($verbose))->file('/src/acme/acme.php');

        self::assertStringContainsString('/src/acme/acme.php', $verbose->fetch());
    }

    /**
     * A path or an archive name is a value: it goes through the formatter, so
     * anything tag-shaped in it has to be handed back rather than applied.
     */
    public function testATagShapedNameIsPrintedRatherThanApplied(): void
    {
        $output = new BufferedOutput(decorated: true);
        $progress = new PlainProgress($output);

        $progress->detail('<fg=red>weird</> · 1 KB');
        $progress->finish();

        $written = $output->fetch();

        self::assertStringContainsString('<fg=red>weird</>', $written);
        self::assertStringNotContainsString("\033[31m", $written);
    }
}
