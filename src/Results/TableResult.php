<?php

namespace WpContent\Cli\Results;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Tui\Component\Component;
use WpContent\Cli\Tui\Component\DataTable;
use WpContent\Cli\Tui\KeyPress;
use WpContent\Cli\Tui\Page;
use WpContent\Cli\Tui\Screen;

class TableResult extends BaseCommandResult implements InteractiveOutput
{
    protected bool $singleton = false;

    protected string $title = '';

    protected string $footer = '';

    /** @var list<string> */
    protected array $requiredColumns;

    protected ?Page $page = null;

    /** @var (callable(int): ?Page)|null */
    protected $loadPage = null;

    /** @var (callable(array<string, mixed>): ?Component)|null */
    protected $onSelect = null;

    /**
     * @param list<string> $columns
     */
    public function __construct(mixed $data, array $columns = [])
    {
        parent::__construct($data);

        $this->requiredColumns = $columns;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Make the result navigable: $loadPage fetches another page on demand, and
     * $onSelect turns the highlighted row into a detail view.
     *
     * @param callable(int): ?Page                        $loadPage
     * @param (callable(array<string, mixed>): ?Component)|null $onSelect
     */
    public function setPager(Page $page, callable $loadPage, ?callable $onSelect = null): self
    {
        $this->loadPage = $loadPage;
        $this->onSelect = $onSelect;
        $this->adoptPage($page);

        return $this;
    }

    public function json(OutputInterface $output): void
    {
        $rows = is_array($this->data) ? $this->data : [];
        $data = $this->singleton ? ($rows[0] ?? null) : $this->data;

        // VERBOSITY_QUIET, because this payload *is* the answer: `-q` silences
        // the diagnostics, not the thing the caller is parsing.
        $output->write(
            Json::encode($data, $output->isVerbose() ? JSON_PRETTY_PRINT : 0),
            false,
            OutputInterface::VERBOSITY_QUIET
        );
    }

    public function interactive(Screen $screen): void
    {
        if ($this->page === null || $this->loadPage === null) {
            return;
        }

        $table = new DataTable(
            $this->page,
            $this->resolveHeaders($this->page->rows),
            $this->title !== '' ? $this->title : 'Results',
            $this->itemLabel(),
            $this->loadPage,
            $this->onSelect,
        );

        $screen->run(
            fn (): array => $table->render($screen->width(), $screen->height()),
            static fn (KeyPress $key): bool => $table->handle($key),
        );

        // Whatever page the user ended on is what human() prints behind the TUI.
        $this->adoptPage($table->page());
    }

    public function human(OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setStyle('box');

        if ($this->title) {
            $table->setHeaderTitle($this->title);
        }
        if ($this->footer) {
            $table->setFooterTitle($this->footer);
        }

        $rows = is_array($this->data) ? $this->data : [];

        if ($rows) {
            $headers = $this->resolveHeaders($rows, $output->isVerbose());
            $horizontal = $this->singleton || count($headers) > 6;

            $table->setHeaders($headers);
            $table->setHorizontal($horizontal);

            for ($i = 0; $i < count($headers); $i++) {
                $table->setColumnMaxWidth($i, $horizontal ? 80 : 30);
            }

            foreach ($rows as $row) {
                $rowData = (array) $row;
                // Emit cells in the header order (and fill in missing columns) so a
                // row whose keys differ in order or count stays aligned with its headers.
                $table->addRow(array_map(
                    fn (int|string $column): string => $this->prepareCellContent($rowData[$column] ?? ''),
                    $headers
                ));
            }
        } else {
            $table->addRow(['No results']);
        }

        $table->render();
    }

    /**
     * Columns to display: the declared ones, or every key of the first row when
     * none were declared (or when the user asked for the verbose rendering).
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<string>
     */
    protected function resolveHeaders(array $rows, bool $allColumns = false): array
    {
        if (!$allColumns && $this->requiredColumns !== []) {
            return $this->requiredColumns;
        }

        $first = is_array($rows[0] ?? null) ? $rows[0] : [];

        return array_map(strval(...), array_keys($first));
    }

    protected function adoptPage(Page $page): void
    {
        $this->page = $page;
        $this->data = $page->rows;
        $this->footer = sprintf(
            'Page %d/%d · %d %s',
            $page->number,
            $page->pages,
            $page->total,
            $this->itemLabel()
        );
    }

    private function itemLabel(): string
    {
        return $this->title !== '' ? strtolower($this->title) : 'results';
    }
}
