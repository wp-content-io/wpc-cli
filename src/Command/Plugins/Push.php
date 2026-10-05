<?php

namespace WpContent\Cli\Command\Plugins;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractPushCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'plugin push', aliases: ['plugin:push'])]
class Push extends AbstractPushCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Plugin;
    }
}
