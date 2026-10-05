<?php

namespace WpContent\Cli\Tui\Component;

use WpContent\Cli\Tui\Key;
use WpContent\Cli\Tui\KeyPress;
use WpContent\Cli\Tui\Text;
use WpContent\Cli\Tui\Theme;

/**
 * The detail panel behind `plugin info` (and Enter on a row of `plugin list`).
 *
 * The API returns far more than a table can show — an overview, prose sections
 * (description, installation, changelog…) and the version history. Each becomes
 * a tab, so the long-form content is readable instead of being crushed into a
 * 13-row vertical table.
 */
final class DetailView implements Component
{
    /** Rows reserved for the title, the tab bar, the rule and the footer. */
    private const CHROME_ROWS = 4;

    private const OVERVIEW_TAB = 'Overview';

    private const VERSIONS_TAB = 'Versions';

    private int $tab = 0;

    private int $scroll = 0;

    /** @var array<string, list<string>>|null lazily rendered, keyed by tab */
    private ?array $panes = null;

    private int $paneWidth = 0;

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $overviewColumns fields worth showing first
     */
    public function __construct(
        private readonly array $data,
        private readonly array $overviewColumns,
        private readonly string $title,
    ) {
    }

    public function render(int $width, int $height): array
    {
        $panes = $this->panes($width);
        $tabs = array_keys($panes);
        $this->tab = max(0, min($this->tab, count($tabs) - 1));

        $body = $panes[$tabs[$this->tab]] ?? [];
        $bodyHeight = max(1, $height - self::CHROME_ROWS);
        $this->scroll = max(0, min($this->scroll, max(0, count($body) - $bodyHeight)));

        $lines = [$this->titleLine($width)];
        $lines[] = $this->tabBar($tabs);
        $lines[] = '<fg=gray>' . str_repeat('─', max(0, $width)) . '</>';

        foreach (array_slice($body, $this->scroll, $bodyHeight) as $line) {
            $lines[] = $line;
        }

        $lines[] = $this->footerLine(count($body), $bodyHeight);

        return $lines;
    }

    public function handle(KeyPress $key): bool
    {
        return match (true) {
            $key->is(Key::Up) => $this->scrollBy(-1),
            $key->is(Key::Down) => $this->scrollBy(1),
            $key->is(Key::PageUp) => $this->scrollBy(-10),
            $key->is(Key::PageDown) => $this->scrollBy(10),
            $key->is(Key::Home) => $this->scrollTo(0),
            $key->is(Key::End) => $this->scrollTo(PHP_INT_MAX),
            $key->is(Key::Left) => $this->switchTab(-1),
            $key->is(Key::Right, Key::Tab) => $this->switchTab(1),
            $key->isChar('q'), $key->is(Key::Escape, Key::Enter) => false,
            default => true,
        };
    }

    private function scrollBy(int $delta): bool
    {
        return $this->scrollTo($this->scroll + $delta);
    }

    private function scrollTo(int $position): bool
    {
        $this->scroll = max(0, $position);

        return true;
    }

    private function switchTab(int $delta): bool
    {
        $tabCount = count($this->panes($this->paneWidth));
        $this->tab = ($this->tab + $delta + $tabCount) % max(1, $tabCount);
        $this->scroll = 0;

        return true;
    }

    /**
     * Build one pane of pre-wrapped lines per tab. Re-rendered when the terminal
     * width changes, cached otherwise.
     *
     * @return array<string, list<string>>
     */
    private function panes(int $width): array
    {
        if ($this->panes !== null && $this->paneWidth === $width) {
            return $this->panes;
        }

        $this->paneWidth = $width;
        $panes = [self::OVERVIEW_TAB => $this->overviewPane($width)];

        foreach ($this->sections() as $name => $content) {
            $lines = Text::wrap(Text::plain($content), max(1, $width - 1));
            if ($lines === []) {
                continue;
            }
            $panes[$this->tabName($name)] = array_map(
                static fn (string $line): string => ' ' . Text::escape($line),
                $lines
            );
        }

        $versions = $this->versionsPane($width);
        if ($versions !== []) {
            $panes[self::VERSIONS_TAB] = $versions;
        }

        return $this->panes = $panes;
    }

    /**
     * @return list<string>
     */
    private function overviewPane(int $width): array
    {
        $fields = $this->overviewColumns !== [] ? $this->overviewColumns : array_keys($this->data);
        $labelWidth = 0;
        foreach ($fields as $field) {
            $labelWidth = max($labelWidth, Text::valueWidth($this->label($field)));
        }
        $labelWidth = min($labelWidth, 24);
        $valueWidth = max(1, $width - $labelWidth - 3);

        $lines = [];
        foreach ($fields as $field) {
            // A record that carries a name (a theme's author) is shown by it;
            // any other array has a tab of its own, or nothing to say here.
            $value = Text::display($this->data[$field] ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            $wrapped = Text::wrap(Text::plain($value), $valueWidth);
            $label = Text::cell($this->label($field), $labelWidth);

            foreach ($wrapped ?: [''] as $index => $line) {
                $lines[] = sprintf(
                    ' <fg=gray>%s</> %s',
                    $index === 0 ? $label : str_repeat(' ', $labelWidth),
                    Text::escape($line)
                );
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function versionsPane(int $width): array
    {
        $versions = $this->data['versions'] ?? null;
        if (!is_array($versions) || $versions === []) {
            return [];
        }

        $labelWidth = 0;
        foreach (array_keys($versions) as $version) {
            $labelWidth = max($labelWidth, Text::valueWidth((string) $version));
        }

        $lines = [];
        foreach ($versions as $version => $link) {
            $lines[] = sprintf(
                ' <options=bold>%s</> <fg=gray>%s</>',
                Text::cell((string) $version, $labelWidth),
                Text::escape(Text::truncate(is_scalar($link) ? (string) $link : '', max(1, $width - $labelWidth - 3)))
            );
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function sections(): array
    {
        $sections = $this->data['sections'] ?? null;
        if (!is_array($sections)) {
            return [];
        }

        $named = [];
        foreach ($sections as $name => $content) {
            if (is_scalar($content) && trim((string) $content) !== '') {
                $named[(string) $name] = (string) $content;
            }
        }

        return $named;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    private function tabName(string $section): string
    {
        return ucfirst(str_replace('_', ' ', $section));
    }

    /**
     * The title is a **value** — the resource's own name, straight off the API
     * — so it is measured as it prints, not as chrome: `<info>` inside a name
     * is six columns of text the escaping hands back to the terminal. Counting
     * it as markup padded the rule that much too long, the line wrapped, and
     * the section then erased fewer rows than it had drawn.
     */
    private function titleLine(int $width): string
    {
        // Collapsed and truncated for the same reason: a name that fills the
        // viewport on its own would push the rule off the line.
        $title = ' ' . Text::truncate(Text::singleLine($this->title), max(1, $width - 4)) . ' ';
        $rule = str_repeat('─', max(0, $width - Text::valueWidth($title) - 2));

        return sprintf('<fg=gray>──</><options=bold>%s</><fg=gray>%s</>', Text::escape($title), $rule);
    }

    /**
     * @param list<string> $tabs
     */
    private function tabBar(array $tabs): string
    {
        $rendered = [];
        foreach ($tabs as $index => $name) {
            $rendered[] = $index === $this->tab
                ? Theme::selected(' ' . Text::escape($name) . ' ')
                : '<fg=gray> ' . Text::escape($name) . ' </>';
        }

        return ' ' . implode('<fg=gray>│</>', $rendered);
    }

    private function footerLine(int $bodyLines, int $bodyHeight): string
    {
        $position = $bodyLines > $bodyHeight
            ? sprintf('%d-%d/%d · ', $this->scroll + 1, min($this->scroll + $bodyHeight, $bodyLines), $bodyLines)
            : '';

        return '<fg=gray>' . $position . '←→ tabs · ↑↓ scroll · q back</>';
    }
}
