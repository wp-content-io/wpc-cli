<?php

namespace WpContent\Cli\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use WpContent\Cli\Api\ApiError;
use WpContent\Cli\Application;
use WpContent\Cli\Tests\EnvGuardTrait;
use WpContent\Cli\Tests\Fake\FakeRegistryClient;
use WpContent\Cli\Tests\SplitOutput;
use WpContent\Cli\Tests\TempDirTrait;

/**
 * The four commands that talk to the registry: what they ask it for, and what
 * they do with the answer.
 *
 * None of them had a test before — reaching them meant reaching through the
 * application to a Guzzle singleton that could not be replaced. They now take a
 * client, so the whole path can be exercised without a network.
 */
final class RegistryCommandsTest extends TestCase
{
    use EnvGuardTrait;
    use TempDirTrait;

    private const LISTING = '/plugins?page=1&per_page=36';

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

    public function testListAsksForTheFirstPageAndRendersTheRows(): void
    {
        $registry = (new FakeRegistryClient())->willReturn(self::LISTING, [
            'plugins' => [
                ['name' => 'Acme Analytics', 'slug' => 'acme-analytics', 'version' => '1.2.0'],
                ['name' => 'Beta Forms', 'slug' => 'beta-forms', 'version' => '0.9.1'],
            ],
            'info' => ['page' => 1, 'pages' => 3, 'results' => 74],
        ]);

        [$exit, $stdout] = $this->wpc($registry, "'plugin list'");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame([self::LISTING], $registry->calls);
        self::assertStringContainsString('acme-analytics', $stdout);
        self::assertStringContainsString('beta-forms', $stdout);
        // The footer counts pages and items apart — it used to show one for the other.
        self::assertStringContainsString('Page 1/3', $stdout);
        self::assertStringContainsString('74', $stdout);
    }

    public function testListPassesThePaginationItWasGiven(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/plugins?page=2&per_page=5', [
            'plugins' => [],
            'info' => ['page' => 2, 'pages' => 2, 'results' => 5],
        ]);

        [$exit] = $this->wpc($registry, "'plugin list' --page=2 --per-page=5");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['/plugins?page=2&per_page=5'], $registry->calls);
    }

    public function testInfoAsksForTheSlugItWasGiven(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/plugins/acme', [
            'name' => 'Acme',
            'slug' => 'acme',
            'version' => '2.0.0',
        ]);

        [$exit, $stdout] = $this->wpc($registry, "'plugin info' acme --output=json");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['/plugins/acme'], $registry->calls);
        self::assertSame('2.0.0', json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['version']);
    }

    /** A slug is one path segment, whatever was typed. */
    public function testInfoEncodesTheSlug(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/plugins/acme%2F..%2Fadmin%3Fx%3D1', ['slug' => 'x']);

        [$exit] = $this->wpc($registry, "'plugin info' 'acme/../admin?x=1' --output=json");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['/plugins/acme%2F..%2Fadmin%3Fx%3D1'], $registry->calls);
    }

    public function testThemeCommandsUseTheThemeCollection(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/themes/acme', ['name' => 'Acme', 'slug' => 'acme']);

        [$exit] = $this->wpc($registry, "'theme info' acme --output=json");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['/themes/acme'], $registry->calls);
    }

    /**
     * A theme is shaped after the WordPress.org themes API, not like a plugin:
     * its rows are read off its own fields, and its author is an object.
     */
    public function testThemesAreListedByTheirOwnFields(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/themes?page=1&per_page=36', [
            'themes' => [$this->theme()],
            'info' => ['page' => 1, 'pages' => 1, 'results' => 1],
        ]);

        [$exit, $stdout] = $this->wpc($registry, "'theme list'");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('downloaded', $stdout);
        self::assertStringContainsString('1234', $stdout);
        self::assertStringContainsString('2026-03-02 10:00:00', $stdout);
        self::assertStringNotContainsString('active_installs', $stdout);
    }

    public function testAThemesAuthorIsShownByName(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/themes/acme', $this->theme());

        [$exit, $stdout] = $this->wpc($registry, "'theme info' acme");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Jane Doe', $stdout);
        self::assertStringContainsString('A clean theme.', $stdout);
        self::assertStringNotContainsString('user_nicename', $stdout);
        self::assertStringNotContainsString('tested', $stdout, 'a theme has no "tested" field');

        // The machine output is the payload as the registry sent it.
        [, $json] = $this->wpc($registry, "'theme info' acme --output=json");
        self::assertSame($this->theme()['author'], json_decode($json, true, flags: JSON_THROW_ON_ERROR)['author']);
    }

    public function testPushUploadsTheArtifactUnderTheResourceField(): void
    {
        $artifact = $this->makeTempDir() . '/acme.zip';
        $this->writeFile($artifact, 'zip');

        $registry = new FakeRegistryClient();

        [$exit] = $this->wpc($registry, "'plugin push' $artifact --message='Ship it'");

        self::assertSame(Command::SUCCESS, $exit);
        self::assertCount(1, $registry->uploads);
        self::assertSame('/plugins', $registry->uploads[0]['endpoint']);
        self::assertSame(['plugin' => $artifact], $registry->uploads[0]['files']);
        self::assertSame(['release_notes' => 'Ship it'], $registry->uploads[0]['fields']);
    }

    public function testPushCleansUpOnlyWhenAsked(): void
    {
        $artifact = $this->makeTempDir() . '/acme.zip';
        $this->writeFile($artifact, 'zip');

        $this->wpc(new FakeRegistryClient(), "'plugin push' $artifact");
        self::assertFileExists($artifact, 'the artifact must survive a plain push');

        $this->wpc(new FakeRegistryClient(), "'plugin push' $artifact --clean");
        self::assertFileDoesNotExist($artifact);
    }

    public function testAFailedPushLeavesTheArtifactAlone(): void
    {
        $artifact = $this->makeTempDir() . '/acme.zip';
        $this->writeFile($artifact, 'zip');

        $registry = (new FakeRegistryClient())->willFail('/plugins', new ApiError(403));

        [$exit] = $this->wpc($registry, "'plugin push' $artifact --clean");

        self::assertSame(Command::FAILURE, $exit);
        self::assertFileExists($artifact, 'nothing was published, so nothing may be deleted');
    }

    /**
     * `init` is scriptable: with no terminal to ask, it writes exactly what it
     * was told and nothing else — the registry is not consulted, since
     * pre-filling only exists to answer a question nobody is being asked.
     */
    public function testInitScaffoldsFromItsArgumentsWithoutATerminal(): void
    {
        $workdir = $this->makeTempDir();
        $registry = (new FakeRegistryClient())->withAuthor(['Author' => 'Jane Doe']);

        [$exit] = $this->inDirectory(
            $workdir,
            fn (): array => $this->wpc(
                $registry,
                "'plugin init' 'Acme Analytics' --author='John Doe' --repository=https://registry.test -n"
            )
        );

        self::assertSame(Command::SUCCESS, $exit);

        // The slug is derived from the name when none is given.
        $generated = (string) file_get_contents("$workdir/acme-analytics/acme-analytics.php");

        self::assertStringContainsString('Plugin Name: Acme Analytics', $generated);
        self::assertStringContainsString('Author: John Doe', $generated);
        self::assertStringNotContainsString('Jane Doe', $generated, 'nothing may be filled in behind the caller');
        // Where the installed plugin will look for its own updates.
        self::assertStringContainsString('Update URI: https://registry.test', $generated);
    }

    /**
     * `init` answers in the format that was asked for, like every other verb:
     * it used to write its confirmation as a sentence on stdout whatever
     * `--output` said, so `wpc plugin init … --output=json | jq -r .path` died
     * on a parse error at the very moment it had worked.
     */
    public function testInitAnswersWithItsPathUnderJson(): void
    {
        $workdir = $this->makeTempDir();

        [$exit, $stdout] = $this->inDirectory(
            $workdir,
            fn (): array => $this->wpc(
                new FakeRegistryClient(),
                "'plugin init' 'Acme Analytics' --output=json -n"
            )
        );

        self::assertSame(Command::SUCCESS, $exit);

        $payload = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        // The directory that was created, which is what the sentence used to name.
        self::assertStringEndsWith('/acme-analytics', $payload['path']);
        self::assertFileExists($payload['path'] . '/acme-analytics.php');
    }

    /**
     * What the caller said wins over what `init` would have filled in.
     *
     * The defaults were seeded with an unconditional `setOption()`, so an
     * explicit value was gone before anything read it: `--plugin-version=2.1.0`
     * was handed straight back to 1.0.0, and `--author` replaced by whatever
     * the registry answered. Both flows did it, so `-n` was no protection.
     */
    public function testInitDoesNotOverwriteWhatItWasGiven(): void
    {
        $workdir = $this->makeTempDir();
        $registry = (new FakeRegistryClient())->withAuthor(['Author' => 'Jane Doe']);

        [$exit] = $this->inDirectory(
            $workdir,
            fn (): array => $this->wpc(
                $registry,
                "'plugin init' 'Acme Analytics' --plugin-version=2.1.0 --author='John Doe' -n"
            )
        );

        self::assertSame(Command::SUCCESS, $exit);

        $generated = (string) file_get_contents("$workdir/acme-analytics/acme-analytics.php");

        self::assertStringContainsString('Version: 2.1.0', $generated);
        self::assertStringNotContainsString('Version: 1.0.0', $generated);
        self::assertStringContainsString('Author: John Doe', $generated);
    }

    /**
     * Under `-n` the registry's author is seeded too.
     *
     * Symfony skips interact() on a non-interactive input, which is where the
     * seeding lived: the file came out with no Author, and the registry refuses
     * a push without one.
     */
    public function testInitSeedsTheRegistryAuthorWithoutATerminal(): void
    {
        $workdir = $this->makeTempDir();
        $registry = (new FakeRegistryClient())->withAuthor(['Author' => 'Jane Doe', 'Author URI' => 'https://jane.test']);

        [$exit] = $this->inDirectory(
            $workdir,
            fn (): array => $this->wpc($registry, "'plugin init' 'Acme Analytics' -n")
        );

        self::assertSame(Command::SUCCESS, $exit);

        $generated = (string) file_get_contents("$workdir/acme-analytics/acme-analytics.php");

        self::assertStringContainsString('Author: Jane Doe', $generated);
        self::assertStringContainsString('Author URI: https://jane.test', $generated);
        self::assertStringContainsString('Version: 1.0.0', $generated);
    }

    /**
     * A name that slugifies to nothing does not scaffold into the caller's own
     * working directory.
     *
     * `Generator` resolves `$root . '/' . ''` back to the cwd, so a
     * punctuation-only or CJK-only name created a hidden `.php` file there and
     * answered `{"path": "<cwd>"}` with exit 0 — a path a `build --push`
     * pipeline then fed onward.
     */
    public function testInitRefusesANameItCannotTurnIntoASlug(): void
    {
        $workdir = $this->makeTempDir();

        [$exit] = $this->inDirectory(
            $workdir,
            fn (): array => $this->wpc(new FakeRegistryClient(), "'plugin init' '###' --output=json -n")
        );

        self::assertSame(Command::INVALID, $exit);
        self::assertSame(
            ['.', '..'],
            array_values((array) scandir($workdir)),
            'nothing may be written where nothing could be named'
        );
    }

    /**
     * The slug argument is slugified too, so it cannot point out of the working
     * directory: taken verbatim, `init "Acme" "../../tmp/x"` scaffolded there.
     */
    public function testInitKeepsTheSlugArgumentInsideTheWorkingDirectory(): void
    {
        $workdir = $this->makeTempDir();

        [$exit, $stdout] = $this->inDirectory(
            $workdir,
            fn (): array => $this->wpc(new FakeRegistryClient(), "'plugin init' 'Acme' '../../tmp/x' --output=json -n")
        );

        self::assertSame(Command::SUCCESS, $exit);

        $payload = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame((string) realpath($workdir) . '/tmp-x', $payload['path']);
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inDirectory(string $directory, callable $work): mixed
    {
        $before = (string) getcwd();
        chdir($directory);

        try {
            return $work();
        } finally {
            chdir($before);
        }
    }

    /**
     * A theme as the registry's Theme entity serializes it.
     *
     * @return array<string, mixed>
     */
    private function theme(): array
    {
        return [
            'name' => 'Acme Theme',
            'slug' => 'acme',
            'version' => '1.0.0',
            'author' => [
                'user_nicename' => 'jane-doe',
                'profile' => '',
                'avatar' => '',
                'display_name' => 'Jane Doe',
                'author' => 'Jane Doe',
                'author_url' => 'https://jane.test',
            ],
            'downloaded' => 1234,
            'description' => 'A clean theme.',
            'requires' => '6.0',
            'requires_php' => '8.1',
            'last_updated' => '2026-03-04',
            'creation_time' => '2026-03-02 10:00:00',
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function wpc(FakeRegistryClient $registry, string $arguments): array
    {
        $application = new Application($registry);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        $output = new SplitOutput();
        $exit = $application->run(new StringInput($arguments), $output);

        return [$exit, $output->fetch()];
    }
}
