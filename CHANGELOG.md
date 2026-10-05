# Changelog

All notable changes to `wpc` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The command names, options, output formats and exit codes are the public API.
Releases up to 2.0.0 predate this repository: they are described here, but
their tags are not published in it.

## [2.1.0] - Unreleased

### Added

- `composer global require wpcontent/wpc-cli` installs `wpc` from Packagist.
- `self-update` accepts a release asset of the public repository
  (`https://github.com/wp-content-io/wpc-cli/releases/download/vX.Y.Z/…`) as
  the download the update manifest pins. The manifest's SHA-256 remains the
  integrity check; 2.0.0 clients ignore such a pin and keep updating from
  `latest/`.

### Changed

- The package is published as `wpcontent/wpc-cli`.
- `self-update` follows redirects over HTTPS only (at most five, without a
  `Referer`), and refuses one that leaves HTTPS with a sentence saying so.
  A pinned URL with userinfo, an explicit port, a dot segment, a query or a
  fragment is no longer trusted.
- Tags are `vX.Y.Z` from this release on. A leading `v` is tolerated
  everywhere a version is read: `wpc --version` prints `2.1.0`, and the update
  notice and `self-update` compare `v2.1.0`, `v2.1.0-3-gabc1234` and a
  manifest version with or without `v` as the releases they name.

## 2.0.0 - 2026-10-02

### Changed (breaking)

- **Exit codes**: `0` success, `2` usage error (unknown command, missing
  argument, unknown option, invalid `--output`, missing file or directory,
  malformed `--slug`/`--filename`, `--header` without `=`, missing repository
  or API key), `1` everything else. A push refused by the API now exits `1`
  instead of `2`.
- An unknown `--output` value is rejected (exit `2`) instead of falling back
  to `human`.
- The API key is required by `list`, `info`, `push` and `build --push`, which
  exit `2` before any network call when it is missing.
- Under `human` and `plain`, stdout carries only the answer: errors and
  progress go to stderr, and step lines replace the progress bar.
- The v1 command names (`plugin:ls`…) and `--paged` keep working but print a
  deprecation notice on stderr — silent under `--output=json`, `-q` and
  `WPC_NO_DEPRECATED_WARNING=1`.
- **JSON output**: every failure is one `{code, message, errors}` object on
  stdout, `code` being the HTTP status or the exit code, never `0`; `message`
  is the registry's own sentence; `-q` no longer suppresses the answer;
  slashes are no longer escaped. New shapes: `init` answers `{"path"}`,
  `manifest --get` answers `{"Header": "value"}`, `self-update` answers an
  object, and `wpc` / `wpc list` answer `{usage, commands, options}`.
- `--header` without `=` is refused (exit `2`).
- In `human` mode on a terminal, `list` and `info` open an interactive view
  (`--output=plain` or `WPC_NO_TUI=1` for the static tables); `info` without a
  slug is an error; the "Did you mean … run instead?" prompt is removed;
  tables are restyled; theme columns are `downloaded`, `creation_time` and
  `description`.
- `init`: an explicit slug is normalised, a wizard runs on a terminal, the
  name is optional (exit `2` when it is missing under `-n`), explicit options
  are no longer overwritten, and the registry's author is pre-filled under
  `-n` too.
- **Docker image**: `:latest` runs PHP 8.5 (use `:php8.2` or `:<tag>-php8.2`
  to stay on 8.2); the image only adds `zip` plus Composer, git and unzip, and
  has no `ENTRYPOINT`.

### Added

- The `<noun> <verb>` grammar (`wpc plugin list`, `wpc theme push`…).
- `self-update`, with `--check`, `--rollback`, `--major`, and a release
  manifest pinning the versioned phar and its checksum.
- `completion` and `help`.
- `WPC_NO_TUI`, `WPC_ACCENT_COLOR`, `WPC_NO_UPDATE_CHECK` and
  `WPC_NO_DEPRECATED_WARNING`.

### Unchanged

- `manifest --get` on a header the file does not declare still answers empty
  with exit `0`.

[2.1.0]: https://github.com/wp-content-io/wpc-cli/releases/tag/v2.1.0
