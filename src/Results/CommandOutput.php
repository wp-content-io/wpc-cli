<?php

namespace WpContent\Cli\Results;

use Symfony\Component\Console\Output\OutputInterface;

interface CommandOutput
{
    public function json(OutputInterface $output): void;

    public function human(OutputInterface $output): void;
}
