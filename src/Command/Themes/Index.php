<?php

namespace WpContent\Cli\Command\Themes;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractListCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'theme list', aliases: ['theme ls', 'theme:list', 'theme:ls'])]
class Index extends AbstractListCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Theme;
    }
}
