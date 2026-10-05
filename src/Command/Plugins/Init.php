<?php

namespace WpContent\Cli\Command\Plugins;

use Symfony\Component\Console\Attribute\AsCommand;
use WpContent\Cli\Command\AbstractInitCommand;
use WpContent\Cli\ResourceType;

#[AsCommand(name: 'plugin init', aliases: ['plugin start', 'plugin:init', 'plugin:start'])]
class Init extends AbstractInitCommand
{
    protected function resourceType(): ResourceType
    {
        return ResourceType::Plugin;
    }

    protected function optionalHeaders(): array
    {
        return [
            'Version' => 'plugin-version',
            'Author' => 'author',
            'Author URI' => 'author-uri',
            'Plugin URI' => 'plugin-uri',
            'Description' => 'description',
            'Requires at least' => 'requires-at-least',
            'Requires PHP' => 'requires-php',
            'License' => 'license',
            'License URI' => 'license-uri',
            'Text Domain' => 'text-domain',
            'Domain Path' => 'domain-path',
            'Network' => 'network',
        ];
    }
}
