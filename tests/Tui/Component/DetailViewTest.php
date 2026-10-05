<?php

namespace WpContent\Cli\Tests\Tui\Component;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use WpContent\Cli\Tui\Component\DetailView;
use WpContent\Cli\Tui\Key;
use WpContent\Cli\Tui\KeyPress;
use WpContent\Cli\Tui\Text;

final class DetailViewTest extends TestCase
{
    private const COLUMNS = ['name', 'slug', 'version', 'short_description'];

    public function testOverviewListsTheDeclaredFields(): void
    {
        $frame = $this->plain($this->view()->render(80, 20));

        self::assertStringContainsString('Name', $frame);
        self::assertStringContainsString('Acme Analytics', $frame);
        self::assertStringContainsString('acme-analytics', $frame);
    }

    public function testAnAuthorObjectIsShownByItsName(): void
    {
        $view = new DetailView([
            'name' => 'Acme Theme',
            'author' => ['user_nicename' => 'jane-doe', 'display_name' => 'Jane Doe', 'author_url' => 'https://jane.test'],
        ], ['name', 'author'], 'Acme Theme');

        $frame = $this->plain($view->render(80, 20));

        self::assertMatchesRegularExpression('/Author\s+Jane Doe/', $frame);
        self::assertStringNotContainsString('jane-doe', $frame);
    }

    public function testEachNonEmptySectionBecomesATab(): void
    {
        $frame = $this->plain($this->view()->render(80, 20));

        self::assertStringContainsString('Overview', $frame);
        self::assertStringContainsString('Description', $frame);
        self::assertStringContainsString('Changelog', $frame);
        self::assertStringContainsString('Versions', $frame);
        // An empty section would be a tab leading to a blank pane.
        self::assertStringNotContainsString('Screenshots', $frame);
    }

    public function testRightArrowMovesToTheNextTab(): void
    {
        $view = $this->view();
        $view->handle(new KeyPress(Key::Right));

        $frame = $this->plain($view->render(80, 20));

        self::assertStringContainsString('without cookies', $frame);
        // The overview fields are no longer the visible pane.
        self::assertStringNotContainsString('acme-analytics', $frame);
    }

    public function testTabsWrapAround(): void
    {
        $view = $this->view();

        // Left from the first tab lands on the last one.
        $view->handle(new KeyPress(Key::Left));

        self::assertStringContainsString('2.4.1', $this->plain($view->render(80, 20)));
    }

    public function testMarkupInSectionsIsStrippedButBareLessThanSurvives(): void
    {
        $view = $this->view();
        $view->handle(new KeyPress(Key::Right));

        $frame = $this->plain($view->render(80, 20));

        self::assertStringNotContainsString('<strong>', $frame);
        self::assertStringContainsString('PHP <8.2', $frame);
    }

    public function testLongPaneScrollsWithinTheViewport(): void
    {
        $view = $this->view();
        $view->handle(new KeyPress(Key::Right));

        // 4 rows go to the chrome, so this leaves a 2-line body — smaller than
        // the wrapped description, which is what makes scrolling observable.
        $height = 6;
        $before = $this->plain($view->render(60, $height));
        $view->handle(new KeyPress(Key::Down));
        $after = $this->plain($view->render(60, $height));

        self::assertNotSame($before, $after, 'scrolling must change what is shown');
        self::assertLessThanOrEqual($height, count($view->render(60, $height)));
    }

    public function testQuitClosesTheView(): void
    {
        self::assertFalse($this->view()->handle(new KeyPress(Key::Char, 'q')));
        self::assertFalse($this->view()->handle(new KeyPress(Key::Escape)));
    }

    /**
     * No frame is wider than the viewport it was drawn for.
     *
     * This is the assertion that was missing, and every rendering bug in here
     * was a way of failing it: a line wider than the section believes it to be
     * wraps, the section then erases fewer rows than it drew, and the panel
     * leaves debris on the terminal it was supposed to clean up after.
     */
    public function testNoLineOverflowsTheViewport(): void
    {
        // Wide enough for the tab bar, which is chrome this component never
        // shortens: four tabs need 48 columns and simply do not fit below that.
        $width = 60;

        foreach ([$this->view(), $this->view(withMarkupInValues: true)] as $index => $view) {
            for ($tab = 0; $tab < 4; $tab++) {
                foreach ($view->render($width, 12) as $line) {
                    self::assertLessThanOrEqual(
                        $width,
                        Text::valueWidth($this->plain([$line])),
                        "view $index, tab $tab: \"$line\""
                    );
                }

                $view->handle(new KeyPress(Key::Right));
            }
        }
    }

    /**
     * A name is a value, not chrome.
     *
     * The title was sized with `width()`, which drops the decoration it is
     * handed, while it is rendered escaped — so `<info>` in a plugin name
     * counted as zero columns and printed as six, and the rule was padded that
     * much too long. Same story for the version keys of the Versions pane.
     */
    public function testAValueThatLooksLikeMarkupIsMeasuredAsItPrints(): void
    {
        $frame = $this->plain($this->view(withMarkupInValues: true)->render(60, 20));

        self::assertStringContainsString('Code </> Snippets', $frame);
    }

    private function view(bool $withMarkupInValues = false): DetailView
    {
        if ($withMarkupInValues) {
            return new DetailView([
                'name' => 'Code </> Snippets',
                'slug' => 'code-snippets',
                'version' => '<info>2.4.1</info>',
                'short_description' => 'Snippets, <em>everywhere</em>.',
                'sections' => ['description' => 'Nothing to see here.'],
                'versions' => ['<info>2.4.1</info>' => 'https://example.test/a-2.4.1.zip'],
            ], self::COLUMNS, 'Code </> Snippets');
        }

        return new DetailView([
            'name' => 'Acme Analytics',
            'slug' => 'acme-analytics',
            'version' => '2.4.1',
            'short_description' => 'Privacy-first analytics.',
            'sections' => [
                'description' => '<p>Collects <strong>page views</strong> without cookies.</p><p>Requires PHP <8.2 to be disabled, and keeps every byte of data on your own server so nothing is ever shared with a third party.</p>',
                'changelog' => '<h4>2.4.1</h4><p>Fix CSV export</p>',
                'screenshots' => '',
            ],
            'versions' => [
                '2.4.1' => 'https://example.test/a-2.4.1.zip',
                '2.4.0' => 'https://example.test/a-2.4.0.zip',
            ],
        ], self::COLUMNS, 'Acme Analytics');
    }

    /**
     * @param list<string> $lines
     */
    private function plain(array $lines): string
    {
        $formatter = new OutputFormatter(false);

        return implode("\n", array_map(static fn (string $line): string => $formatter->format($line) ?? '', $lines));
    }
}
