# wp-content.io CLI

[![CI](https://github.com/wp-content-io/wpc-cli/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/wp-content-io/wpc-cli/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/wpcontent/wpc-cli)](https://packagist.org/packages/wpcontent/wpc-cli)
[![License: MIT](https://img.shields.io/github/license/wp-content-io/wpc-cli)](LICENSE)
[![Docker pulls](https://img.shields.io/docker/pulls/wpcontent/wpc-cli)](https://hub.docker.com/r/wpcontent/wpc-cli)

`wpc` builds private WordPress plugins and themes and pushes them to your
[wp-content.io](https://wp-content.io) registry — from a terminal or from CI.
Full documentation: [docs.wp-content.io/cli](https://docs.wp-content.io/cli).

## Installation

`wpc` needs PHP 8.2 or newer with the `zip` extension — or only Docker.

### Phar (recommended)

Download the latest release and its checksum, verify, and put it on your PATH:

```bash
curl -fsSLO https://github.com/wp-content-io/wpc-cli/releases/latest/download/wpc.phar
curl -fsSLO https://github.com/wp-content-io/wpc-cli/releases/latest/download/wpc.phar.sha256
echo "$(cat wpc.phar.sha256)  wpc.phar" | sha256sum -c -     # macOS: shasum -a 256 -c -
chmod +x wpc.phar
sudo mv wpc.phar /usr/local/bin/wpc
wpc --version
```

The same phar is mirrored at `https://downloads.wp-content.io/wpc-cli/latest/wpc.phar`
(and `…/<version>/wpc.phar` for a given release). Every release phar carries a
signed build provenance, which the [GitHub CLI](https://cli.github.com) can check:

```bash
gh attestation verify wpc.phar --repo wp-content-io/wpc-cli
```

### Docker

Images are published to Docker Hub and the GitHub Container Registry, for
`linux/amd64` and `linux/arm64`, one per supported PHP version. They also
contain Composer, git and unzip, so a CI job can install dependencies and build
in the same image.

```bash
docker run --rm -v "$PWD:/app" -e WPC_API_KEY wpcontent/wpc-cli wpc plugin build --push
```

| Tag | Contents |
| --- | --- |
| `latest` | Latest stable release, newest PHP |
| `php8.2` … `php8.5` | Latest stable release on that PHP version |
| `<version>` (e.g. `2.1.0`) | That release, newest PHP — never moves |
| `<version>-php8.2` … | That release on that PHP version — never moves |

`ghcr.io/wp-content-io/wpc-cli` carries the same tags. Pin a `<version>` tag in
CI for reproducible builds.

### Composer

Install it with Composer (PHP 8.2+ with the `zip` extension), and make sure
Composer's global `vendor/bin` directory is in your PATH:

```bash
composer global require wpcontent/wpc-cli
```

## Keeping wpc up to date

Update the installed phar to the latest release (the download is the release's
versioned phar, verified against the SHA-256 checksum published with it, and the
previous version is kept for rollback):

```bash
wpc self-update              # install the latest version
wpc self-update --check      # only report whether an update is available
wpc self-update --major      # also allow a new major version (e.g. 2.x to 3.0)
wpc self-update --rollback   # restore the previous version
```

`self-update` never moves backwards: a build at or past the published release
(a pre-release such as `2.0.0-beta`, a build from a branch) is reported as up to
date. It never crosses a major version on its own either — a major release may
rename the commands or options a script relies on — so it refuses, and says so,
until it is run with `--major`. Only stable releases are published to the
update channel; pre-releases are downloaded explicitly from their versioned URL
(`https://downloads.wp-content.io/wpc-cli/<version>/wpc.phar`, or the assets of
the [GitHub pre-release](https://github.com/wp-content-io/wpc-cli/releases)).

`self-update` replaces the phar only. A copy installed with Composer is updated
with Composer: `composer global update wpcontent/wpc-cli`.

When run interactively from the phar, wpc also prints a one-line notice on
**stderr** if a newer version exists. This notice is automatically suppressed for
automation (with `--output=json`, `-q/--quiet`, `-n/--no-interaction`, when the
output is piped, or in CI). Set `WPC_NO_UPDATE_CHECK=1` to disable it entirely.

## Usage

Commands read as a noun and a verb — what you are managing, then what you are
doing to it.

```bash
wpc                            # what wpc manages, and the global options
wpc plugin                     # the six things you can do to a plugin
wpc plugin build               # build the current directory
wpc theme push dist/acme.zip
wpc plugin push --help
```

The v1 names (`wpc plugin:ls`, `wpc theme:push`…) are permanent aliases: they
still work, and print a one-line notice on **stderr** pointing at the new
spelling. `WPC_NO_DEPRECATED_WARNING=1` silences it.

| v1 | v2 |
| --- | --- |
| `plugin:start` | `plugin init` |
| `plugin:ls` | `plugin list` |
| `plugin:info` `plugin:build` `plugin:push` `plugin:manifest` | same verb, space instead of the colon |

## Global options
`--repository=<url>` Provide custom repository API url (default is https://registry.wp-content.io).   
`--api-key=<key>` API Key to use (or `WPC_API_KEY`). Required by `list`, `info`, `push` and `build --push`, which fail with exit code 2 before contacting the registry when it is missing; `init`, `build`, `manifest` and `self-update` work without it.   
`--output=<format>` Response format: `human` (default), `plain` or `json`.   

## Interactive mode

On a real terminal, `wpc` is interactive by default: `plugin list` and
`theme list` are browsed with the arrow keys, `plugin info` opens a tabbed
detail panel, and `plugin init` walks you through a wizard.

```
↑ ↓     move between rows          ⏎    open details
← →     previous / next page       /    filter the current page
q       quit                       esc  close the panel (or clear the filter)
```

Pages are fetched as you walk into them, and quitting leaves the page you ended
on printed in your terminal.

`plugin init` is the only command that asks questions — <kbd>esc</kbd> at any of
them abandons the whole thing, and nothing is written. Everything else takes its
arguments up front, so what you type says what will happen.

This never applies to automation. `--output=json` is always raw, and the plain
tables are used whenever the CLI is not talking to a human — piped output, CI,
`-n/--no-interaction`, `-q/--quiet`, `--no-ansi`, or a terminal without `stty`.
Use `--output=plain` (or `WPC_NO_TUI=1`) to force the static rendering on a
terminal.

## Scripting wpc

**stdout is the answer, stderr is everything else.** A command that fails writes
nothing to stdout, so a redirect never collects an error message where it
expected a result:

```bash
wpc plugin build ./src > artifact.txt || { cat artifact.txt; exit 1; }   # empty on failure
```

**`--output=json` answers in JSON even when it fails** — including for a mistyped
command or a missing argument. Errors come back as one object on stdout:

```json
{ "code": 404, "message": "Not Found", "errors": {} }
```

`code` is the HTTP status when the failure came from the registry, and a non-zero
value otherwise. Use the **exit code** to tell success from failure:

| Code | Meaning |
| --- | --- |
| `0` | Success. |
| `2` | The command was called wrongly: a missing file, a malformed option, an unknown command. |
| `1` | Everything else: the registry was unreachable, refused the key, or answered with an error. |

```bash
if payload=$(wpc plugin info acme --output=json); then
  echo "$payload" | jq -r .version
else
  echo "$payload" | jq -r .message >&2
fi
```

`-q` silences the diagnostics, not the answer: the JSON payload is still written.

**One header at a time.** `manifest --get` prints the bare value, so it can be
interpolated straight into a variable — and answers with an object when JSON is
what was asked for. A header the file does not carry (or a field name that does
not exist) answers with an empty value and exit `0`, as in 1.x, so an optional
header never aborts a `set -e` script; a notice on stderr says what was missing
(silent under `--output=json` and `-q`):

```bash
VERSION=$(wpc plugin manifest acme.php --get Version)                    # 1.0.0
wpc plugin manifest acme.php --get Version --output=json                # {"Version":"1.0.0"}
```

**Discovering the commands.** `wpc`, `wpc list` and `wpc plugin` answer
`--output=json` with the commands they would have drawn, so a wrapper does not
have to parse the screen:

```bash
wpc plugin --output=json | jq -r '.commands[].name'                      # plugin build, plugin info, …
```

### Accent colour

The highlighted row, the active tab and the insertion point use the
wp-content.io button colour (`#4433db`). Everything else stays on your terminal's own palette.
Set `WPC_ACCENT_COLOR` to a hex value or an ANSI colour name to change it:

```bash
export WPC_ACCENT_COLOR="#e11d48"
export WPC_ACCENT_COLOR=cyan
```

The text drawn on top of the accent switches between black and white on its own,
whichever stays readable, and a hex colour is mapped down to whatever the
terminal supports. An unrecognised value falls back to the default.

## Environment variables
```
WPC_REPO_URL=https://registry.wp-content.io
WPC_API_KEY=your-api-key
WPC_NO_UPDATE_CHECK=1
WPC_NO_TUI=1
WPC_ACCENT_COLOR=#4433db
WPC_NO_DEPRECATED_WARNING=1
```

## Commands

The CLI manages both **plugins** and **themes**, with the same six verbs each:
`init`, `manifest`, `build`, `push`, `list` and `info` — plus `self-update` to upgrade the CLI
itself. Run `wpc plugin` to see them, or browse the full reference at
[docs.wp-content.io](https://docs.wp-content.io/cli/install).

Shell completion knows the noun/verb shape:

```bash
eval "$(wpc completion bash)"   # or zsh, fish
```

## Contributing

Bug reports and pull requests are welcome — please read
[CONTRIBUTING.md](CONTRIBUTING.md) first. Contributions are accepted under the
[Developer Certificate of Origin](https://developercertificate.org/): sign off
your commits with `git commit -s`. This project follows the
[Contributor Covenant](CODE_OF_CONDUCT.md).

The changes in each release are listed in [CHANGELOG.md](CHANGELOG.md).

## Security

Please do not report security problems in public issues: see
[SECURITY.md](SECURITY.md) for private reporting.

## License

`wpc` is open-source software licensed under the [MIT license](LICENSE).
The wp-content.io service it talks to is not part of this repository.