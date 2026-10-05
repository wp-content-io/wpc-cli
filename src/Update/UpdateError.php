<?php

namespace WpContent\Cli\Update;

use RuntimeException;

/**
 * A self-update failure whose message is already written for a person — the
 * download host's answer, a refused major version — and is shown as it is,
 * without the "Update failed:" prefix a raw exception gets.
 */
final class UpdateError extends RuntimeException
{
}
