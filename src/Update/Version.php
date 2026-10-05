<?php

namespace WpContent\Cli\Update;

/**
 * Reads the version strings `self-update` has to compare: the one Box stamped
 * into the running phar, and the one the release manifest announces.
 *
 * Only a release-shaped version takes part in a comparison — `X.Y.Z`, with an
 * optional pre-release suffix (`2.0.0-beta`). Two other shapes exist in the
 * wild and are deliberately handled differently:
 *
 * - **`git describe` output** (`1.0.2-57-g3156e95`): a build made 57 commits
 *   after the 1.0.2 tag. It is read as its base tag, which places it *at* that
 *   release for the comparison: up to date while 1.0.2 is the latest one (it
 *   must not be "updated" down to the tag it was built on), and behind as soon
 *   as anything newer is published. It is ahead of 1.0.2, but nothing tells
 *   whether it is ahead of 1.0.3 — the published release wins that doubt.
 * - **anything else** — the `@cli_version@` placeholder of a phar Box could not
 *   stamp, a bare commit hash — is unknown, and the caller falls back to the
 *   checksum comparison, as it did before versions were read at all.
 */
final class Version
{
    /** One leading `v`, and only in front of a number: `v2.1.0`, not `vendor`. */
    private const PREFIX = '/^[vV](?=\d)/';

    private const RELEASE = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?$/';

    private const DESCRIBE = '/^(?<base>.+)-\d+-g[0-9a-f]{4,40}$/';

    /**
     * The comparable form of $version, or null when it is not a release.
     */
    public static function release(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $version = self::display($version);

        // Checked before the release pattern: a describe suffix is itself a
        // legal pre-release tag, and `1.0.2-57-g3156e95` would otherwise be
        // read as a pre-release of 1.0.2 — older than the tag it was built on.
        if (preg_match(self::DESCRIBE, $version, $matches) === 1) {
            $version = $matches['base'];
        }

        return preg_match(self::RELEASE, $version) === 1 ? $version : null;
    }

    /**
     * $version as a person should read it: without the `v` of the tag it was
     * stamped from. Box writes the git tag verbatim, and tags are `vX.Y.Z`
     * from 2.1.0 on, so the running version arrives as `v2.1.0` — which is
     * shown as `2.1.0`, the spelling of the manifest, the docs and every
     * release before it. Only *one* `v` goes: anything stranger is left as it
     * is, for {@see release()} to turn down.
     */
    public static function display(string $version): string
    {
        return (string) preg_replace(self::PREFIX, '', trim($version));
    }

    public static function major(string $release): int
    {
        return (int) strtok($release, '.');
    }
}
