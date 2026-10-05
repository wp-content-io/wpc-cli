<?php

namespace WpContent\Cli\Command\Plugins;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractBuildCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'plugin build', aliases: ['plugin:build'])]
class Build extends AbstractBuildCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Plugin;
    }
}
