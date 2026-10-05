<?php

namespace WpContent\Cli\Builder;

/**
 * Reports what a build is doing, without the builder having to know how (or
 * whether) it is being displayed.
 *
 * The builder used to write to an OutputInterface directly, which tied it to
 * one rendering. Now the same run can be silent under `--output=json`, verbose
 * under `--output=plain -v`, or a live step list on a terminal.
 */
interface BuildProgress
{
    /** Move on to a named stage; the previous one is marked done. */
    public function step(string $label): void;

    /** Attach a short detail to the current stage (a count, a size…). */
    public function detail(string $detail): void;

    /** A single file was added to the archive. */
    public function file(string $path): void;

    /** All stages completed. */
    public function finish(): void;

    /** The current stage failed, and no further one will run. */
    public function fail(string $message): void;
}
