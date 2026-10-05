<?php

namespace WpContent\Cli\Command\Themes;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractInfoCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'theme info', aliases: ['theme:info'])]
class Info extends AbstractInfoCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Theme;
    }
}
