<?php

namespace WpContent\Cli\Command\Plugins;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractManifestCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'plugin manifest', aliases: ['plugin:manifest'])]
class Manifest extends AbstractManifestCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Plugin;
    }
}
