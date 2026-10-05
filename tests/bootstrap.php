<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Output\AnsiColorMode;
use Symfony\Component\Console\Terminal;

// The TUI tests read back the escape sequences a frame is made of. Symfony maps
// a hex colour down to whatever the terminal running the suite reports, so the
// same assertion passed locally (COLORTERM=truecolor) and failed in CI, where
// the alpine image sets nothing. Pin the mode so a frame renders identically
// wherever the suite runs.
Terminal::setColorMode(AnsiColorMode::Ansi24);
