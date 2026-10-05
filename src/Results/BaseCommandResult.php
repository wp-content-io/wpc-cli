<?php

namespace WpContent\Cli\Results;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Tui\Text;

abstract class BaseCommandResult implements CommandOutput
{
    public function __construct(protected mixed $data)
    {
    }

    abstract public function json(OutputInterface $output): void;

    abstract public function human(OutputInterface $output): void;

    protected function prepareCellContent(mixed $data): string
    {
        if (is_scalar($data)) {
            return $this->renderCellString((string) $data);
        }

        // A record with a name — a theme's author — is that name, not a table
        // of its avatar and profile URLs nested inside the cell.
        $named = is_array($data) ? Text::display($data) : null;
        if ($named !== null) {
            return $this->renderCellString($named);
        }

        if (is_array($data)) {
            return $this->renderCellArray($data);
        }

        return Json::encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    protected function renderCellArray(array $data): string
    {
        $output = new BufferedOutput();

        $table = new Table($output);
        $table->setStyle('compact');

        foreach ($data as $key => $row) {
            $table->setColumnMaxWidth(1, 60);
            $table->addRow([$key, $this->prepareCellContent($row)]);
        }

        $table->render();

        return $output->fetch();
    }

    /**
     * Escaped last, exactly as {@see Text::cell()} does it and for the same
     * reason: what comes out of here is handed to the table formatter, and
     * `Text::plain()` only strips what *looks like* a tag — a name, then
     * optional attributes — so that a bare `<` survives. `<fg=red>` is neither
     * (the `=` is not whitespace), and it went through to recolour the rest of
     * the table and shift that row's border by a column.
     */
    private function renderCellString(string $text): string
    {
        return Text::escape(Text::plain($text));
    }
}
