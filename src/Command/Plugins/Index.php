<?php

namespace WpContent\Cli\Command\Plugins;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractListCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'plugin list', aliases: ['plugin ls', 'plugin:list', 'plugin:ls'])]
class Index extends AbstractListCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Plugin;
    }
}
