<?php

namespace WpContent\Cli\Command\Themes;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractManifestCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'theme manifest', aliases: ['theme:manifest'])]
class Manifest extends AbstractManifestCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Theme;
    }
}
