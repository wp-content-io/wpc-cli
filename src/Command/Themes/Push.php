<?php

namespace WpContent\Cli\Command\Themes;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractPushCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'theme push', aliases: ['theme:push'])]
class Push extends AbstractPushCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Theme;
    }
}
