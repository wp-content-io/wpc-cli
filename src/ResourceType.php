<?php

namespace WpContent\Cli;

/**
 * The two resource kinds the CLI manages. Every difference between the
 * `plugin` and `theme` command families (endpoint, upload field, header
 * names, main file...) is derived from this enum, so the command logic can
 * stay shared.
 */
enum ResourceType: string
{
    case Plugin = 'plugin';
    case Theme = 'theme';

    /**
     * The first word of every resource command, e.g. ["plugin", "theme"].
     * Single source of truth for the grammar {@see \WpContent\Cli\CommandLine} parses.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /** Human label, e.g. "Plugin" / "Theme". */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** One-liner for the root help screen, e.g. "Manage plugins". */
    public function manageDescription(): string
    {
        return 'Manage ' . $this->collection();
    }

    /** API collection endpoint and response key, e.g. "plugins" / "themes". */
    public function collection(): string
    {
        return $this->value . 's';
    }

    /**
     * The collection's endpoint, e.g. "/plugins" — where it is listed and
     * where a new version is uploaded.
     *
     * One spelling for every call: the upload used to be relative ("plugins")
     * while every read was absolute, and the two only meet the same URL as long
     * as the repository URL has no path of its own.
     */
    public function collectionPath(): string
    {
        return '/' . $this->collection();
    }

    /**
     * One resource's endpoint, e.g. "/plugins/acme".
     *
     * The slug is whatever was typed: encoded, so a `/`, a `?` or a space in it
     * stays part of the slug instead of quietly asking for another URL.
     */
    public function recordPath(string $slug): string
    {
        return $this->collectionPath() . '/' . rawurlencode($slug);
    }

    /** Multipart field name expected by the API upload endpoint. */
    public function uploadField(): string
    {
        return $this->value;
    }

    /**
     * The columns `list` shows, one row per resource.
     *
     * Per type because the registry does not describe both the same way: a
     * theme is shaped after the WordPress.org themes API, so it counts
     * `downloaded` where a plugin counts `active_installs`, and dates itself
     * with `creation_time` where a plugin has `added`. One shared set left
     * half of every theme row blank.
     *
     * @return list<string>
     */
    public function listColumns(): array
    {
        return match ($this) {
            self::Plugin => ['name', 'slug', 'version', 'active_installs', 'added', 'last_updated'],
            self::Theme => ['name', 'slug', 'version', 'downloaded', 'creation_time', 'last_updated'],
        };
    }

    /**
     * The fields `info` shows, and the detail panel `list` opens on a row —
     * the same set in both, so drilling in and asking directly agree.
     *
     * A theme has no `tested`, no `author_profile` and no `short_description`
     * (its `description` is the whole text), but a `homepage` and a preview.
     *
     * @return list<string>
     */
    public function detailColumns(): array
    {
        return match ($this) {
            self::Plugin => [
                'name', 'slug', 'version', 'author', 'author_profile', 'short_description',
                'active_installs', 'requires', 'tested', 'requires_php', 'download_link',
                'added', 'last_updated',
            ],
            self::Theme => [
                'name', 'slug', 'version', 'author', 'description',
                'downloaded', 'requires', 'requires_php', 'homepage', 'preview_url', 'download_link',
                'creation_time', 'last_updated',
            ],
        };
    }

    /** The "<Type> Name" header that identifies the main file. */
    public function nameHeader(): string
    {
        return $this->label() . ' Name';
    }
}
