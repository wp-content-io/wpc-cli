<?php

namespace WpContent\Cli\Results;

use WpContent\Cli\Tui\Component\DetailView;
use WpContent\Cli\Tui\KeyPress;
use WpContent\Cli\Tui\Screen;

class SingleResult extends TableResult
{
    private bool $navigable = false;

    /**
     * @param list<string> $columns
     */
    public function __construct(mixed $data, array $columns = [])
    {
        parent::__construct([$data], $columns);
        $this->singleton = true;
    }

    /**
     * Opt into the detail panel. Off by default because most single results are
     * one-shot confirmations (a push, a build): those must print and return, not
     * trap the user in a view they have to dismiss.
     */
    public function navigable(): self
    {
        $this->navigable = true;

        return $this;
    }

    /**
     * A single record has nothing to paginate: it opens straight into the
     * detail panel, where the long-form sections are actually readable.
     */
    public function interactive(Screen $screen): void
    {
        $record = $this->record();

        if (!$this->navigable || $record === []) {
            return;
        }

        $view = new DetailView($record, $this->requiredColumns, $this->title !== '' ? $this->title : 'Details');

        $screen->run(
            fn (): array => $view->render($screen->width(), $screen->height()),
            static fn (KeyPress $key): bool => $view->handle($key),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        $rows = is_array($this->data) ? $this->data : [];
        $record = $rows[0] ?? null;

        if (!is_array($record)) {
            return [];
        }

        /** @var array<string, mixed> $record */
        return $record;
    }
}
