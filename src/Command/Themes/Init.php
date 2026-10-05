<?php

namespace WpContent\Cli\Command\Themes;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractInitCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'theme init', aliases: ['theme start', 'theme:init', 'theme:start'])]
class Init extends AbstractInitCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Theme;
    }

    protected function optionalHeaders(): array
    {
        return [
            'Version' => 'theme-version',
            'Author' => 'author',
            'Author URI' => 'author-uri',
            'Theme URI' => 'theme-uri',
            'Description' => 'description',
            'Requires at least' => 'requires-at-least',
            'Requires PHP' => 'requires-php',
            'License' => 'license',
            'License URI' => 'license-uri',
            'Text Domain' => 'text-domain',
            'Domain Path' => 'domain-path',
            'Tags' => 'tags',
        ];
    }
}
