<?php

namespace WpContent\Cli\Command;

use Closure;
use Phar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use WpContent\Cli\Application;
use WpContent\Cli\Results\CommandFailure;
use WpContent\Cli\Results\OutputRouter;
use WpContent\Cli\Results\StatusResult;
use WpContent\Cli\Update\UpdateError;
use WpContent\Cli\Update\Updater;

#[AsCommand(name: 'self-update')]
class SelfUpdate extends Command
{
    public const PHAR_URL = 'https://downloads.wp-content.io/wpc-cli/latest/wpc.phar';
    public const SHA_URL = 'https://downloads.wp-content.io/wpc-cli/latest/wpc.phar.sha256';
    public const MANIFEST_URL = 'https://downloads.wp-content.io/wpc-cli/latest/manifest.json';

    /** @var Closure(): ?Updater */
    private readonly Closure $updaterFactory;

    /**
     * The router decides the format; the factory hands over the updater, or
     * null when not running from a phar. It is a seam for the tests, which run
     * from source: by default it is the installed phar against the published
     * release.
     *
     * @param (Closure(): ?Updater)|null $updaterFactory
     */
    public function __construct(private readonly OutputRouter $router, ?Closure $updaterFactory = null)
    {
        parent::__construct();

        $this->updaterFactory = $updaterFactory ?? static function (): ?Updater {
            $pharPath = Phar::running(false);

            return $pharPath === ''
                ? null
                : new Updater(
                    $pharPath,
                    self::PHAR_URL,
                    self::SHA_URL,
                    manifestUrl: self::MANIFEST_URL,
                    localVersion: Application::VERSION,
                );
        };
    }

    protected function configure(): void
    {
        $this->setDescription('Update wpc to the latest version')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only report whether an update is available')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Restore the previously installed version')
            ->addOption('major', null, InputOption::VALUE_NONE, 'Allow the update to cross a major version (e.g. 2.x to 3.0)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $updater = ($this->updaterFactory)();
        if ($updater === null) {
            $this->router->display($output, new CommandFailure(
                'self-update is only available from the packaged phar (' . self::PHAR_URL . ').',
                Command::FAILURE
            ));

            return Command::FAILURE;
        }

        try {
            if ($input->getOption('rollback')) {
                $updater->rollback();
                $this->report($output, 'Rolled back to the previous version.', ['rolled_back' => true]);

                return Command::SUCCESS;
            }

            $hasUpdate = $updater->hasUpdate();
            $major = $hasUpdate ? $updater->majorUpgrade() : null;
            $target = $updater->publishedVersion();

            if ($input->getOption('check')) {
                $this->report(
                    $output,
                    match (true) {
                        $major !== null => sprintf('wpc %s is available. It is a new major version: run "wpc self-update --major" to install it.', $major),
                        $hasUpdate => sprintf('A new version of wpc%s is available. Run "wpc self-update" to install it.', $target !== null ? " ($target)" : ''),
                        default => 'wpc is already up to date.',
                    },
                    ['update_available' => $hasUpdate, 'major' => $major !== null],
                    $hasUpdate ? 'comment' : 'info'
                );

                return Command::SUCCESS;
            }

            if (!$hasUpdate) {
                $this->report($output, 'wpc is already up to date.', ['updated' => false]);

                return Command::SUCCESS;
            }

            // A major version may rename the commands or options a pipeline
            // relies on, and pipelines update themselves: crossing one is a
            // decision, never a side effect of a scheduled `self-update`.
            if ($major !== null && !$input->getOption('major')) {
                $this->router->display($output, new CommandFailure(
                    sprintf('wpc %s is a new major version and may change commands or options your scripts rely on. Run "wpc self-update --major" to install it.', $major),
                    Command::FAILURE
                ));

                return Command::FAILURE;
            }

            $updater->update(allowMajor: true);
            $this->report(
                $output,
                $target !== null ? sprintf('wpc has been updated to %s.', $target) : 'wpc has been updated to the latest version.',
                ['updated' => true]
            );

            return Command::SUCCESS;
        } catch (UpdateError $e) {
            // Already a sentence: the download host's answer, without the S3
            // XML Guzzle used to quote.
            $this->router->display($output, new CommandFailure($e->getMessage(), Command::FAILURE));

            return Command::FAILURE;
        } catch (Throwable $e) {
            // Even the update path answers in the format that was asked for:
            // `self-update --check --output=json` is read by scheduled jobs, and
            // used to hand them a sentence the first time the fetch failed.
            $this->router->display($output, new CommandFailure(
                sprintf('Update failed: %s', $e->getMessage()),
                Command::FAILURE
            ));

            return Command::FAILURE;
        }
    }

    /**
     * A sentence for a person, an object for a script — the payload is what
     * `--output=json` promises, the sentence is what a terminal is for.
     *
     * @param array<string, bool> $payload
     */
    private function report(OutputInterface $output, string $message, array $payload, string $style = 'info'): void
    {
        $this->router->display($output, new StatusResult($message, $payload, $style));
    }
}
