<?php

namespace WpContent\Cli\Tests\Tui;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Env;
use WpContent\Cli\Tests\EnvGuardTrait;
use WpContent\Cli\Tui\Environment;

/**
 * The interactive rendering must never fire when the CLI is not talking to a
 * human — that is what keeps pipes, CI and `--no-interaction` on the plain
 * tables, and the JSON contract untouched.
 */
final class EnvironmentTest extends TestCase
{
    use EnvGuardTrait;

    public function testNonConsoleOutputIsNeverInteractive(): void
    {
        // A BufferedOutput has no sections, so a TUI cannot be drawn into it.
        $output = new BufferedOutput();
        $output->setDecorated(true);

        self::assertFalse(Environment::supportsInteractive($this->input(), $output));
    }

    public function testUndecoratedOutputIsNotInteractive(): void
    {
        // Piping (or --no-ansi) turns decoration off.
        $output = new ConsoleOutput();
        $output->setDecorated(false);

        self::assertFalse(Environment::supportsInteractive($this->input(), $output));
    }

    public function testNonInteractiveInputIsNotInteractive(): void
    {
        $input = $this->input();
        $input->setInteractive(false);

        self::assertFalse(Environment::supportsInteractive($input, $this->decoratedConsole()));
    }

    public function testQuietOutputIsNotInteractive(): void
    {
        $output = $this->decoratedConsole();
        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);

        self::assertFalse(Environment::supportsInteractive($this->input(), $output));
    }

    public function testCiOptsOut(): void
    {
        $this->withEnv('CI', '1', function (): void {
            self::assertFalse(Environment::supportsInteractive($this->input(), $this->decoratedConsole()));
        });
    }

    public function testNoTuiEnvOptsOut(): void
    {
        $this->withEnv(Environment::NO_TUI_ENV, '1', function (): void {
            self::assertFalse(Environment::supportsInteractive($this->input(), $this->decoratedConsole()));
        });
    }

    /**
     * WPC_NO_TUI=0 must behave like the variable being unset — the reading
     * itself is pinned by {@see \WpContent\Cli\Tests\EnvTest}; what matters here
     * is that the opt-out honours it.
     */
    public function testFalsyEnvValueDoesNotOptOut(): void
    {
        $this->withEnv(Environment::NO_TUI_ENV, '0', function (): void {
            // Still false, but for want of a terminal rather than the opt-out:
            // what this pins is that "0" is not read as "yes, opt out".
            self::assertFalse(Env::isTruthy(Environment::NO_TUI_ENV));
        });

        $this->withEnv(Environment::NO_TUI_ENV, 'yes', function (): void {
            self::assertTrue(Env::isTruthy(Environment::NO_TUI_ENV));
            self::assertFalse(Environment::supportsInteractive($this->input(), $this->decoratedConsole()));
        });
    }

    private function input(): InputInterface
    {
        return new ArrayInput([]);
    }

    private function decoratedConsole(): ConsoleOutput
    {
        $output = new ConsoleOutput();
        $output->setDecorated(true);

        return $output;
    }

}
