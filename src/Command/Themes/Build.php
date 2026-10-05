<?php

namespace WpContent\Cli\Command\Themes;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractBuildCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'theme build', aliases: ['theme:build'])]
class Build extends AbstractBuildCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Theme;
    }
}
