<?php

namespace WpContent\Cli\Command\Plugins;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractInfoCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'plugin info', aliases: ['plugin:info'])]
class Info extends AbstractInfoCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Plugin;
    }
}
