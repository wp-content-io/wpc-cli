# Contributing to wpc

Thanks for your interest in improving `wpc`, the wp-content.io CLI. Bug
reports, documentation fixes and pull requests are all welcome.

Before starting on a larger change, please open an issue to discuss it:
`wpc`'s commands, options, output formats and exit codes are a public API that
customers' CI pipelines depend on (and update themselves to through
`self-update`), so some changes cannot be accepted however good the code is.

Security problems are **not** reported through issues or pull requests — see
[SECURITY.md](SECURITY.md).

## Development setup

Requirements: PHP 8.2 or newer with the `zip` extension, Composer 2, git.

```bash
git clone https://github.com/wp-content-io/wpc-cli.git
cd wpc-cli
composer install        # also installs the isolated build tools in vendor-bin/
php bin/wpc             # run the CLI from source
php bin/wpc plugin list --help
```

Running from source (`php bin/wpc`) executes `src/` directly: no build step,
and an installed `wpc` phar is left alone. `self-update` and the update notice
are inert from source.

To try commands that talk to a registry, point `wpc` at it with environment
variables or flags — the CLI does not read a `.env` file:

```bash
export WPC_REPO_URL=https://registry.wp-content.io   # or any registry you have access to
export WPC_API_KEY=...                               # an API key from your dashboard
```

Use a dedicated key for development, and never commit it (`.env` and `.envrc`
are ignored for that reason).

## Checks

All three must pass; CI runs them on PHP 8.2, 8.3, 8.4 and 8.5.

```bash
composer test       # PHPUnit
composer analyse    # PHPStan, level 6
composer cs         # PHP-CS-Fixer, dry run — `composer cs:fix` applies it
```

To build the phar locally (needs a git tag reachable from `HEAD`, which Box
uses as the version):

```bash
composer box -- compile
php wpc.phar --version
```

## Contracts to respect

[CLAUDE.md](CLAUDE.md) is the detailed architecture guide (written for humans
and coding agents alike). The parts a pull request most often trips over:

- **Streams and exit codes** — section *Streams and exit codes*. stdout is the
  answer and nothing else; `--output=json` answers with one object even on
  failure; exit `0` success, `2` usage error, `1` everything else. Pinned by
  `tests/OutputContractTest.php`.
- **The command contract** — section *Command grammar*.
  `tests/fixtures/command-contract.json` pins every command's name, aliases,
  arguments and options. If your change modifies it on purpose, update the
  fixture and say so in the pull request. The v1 names and options
  (`CommandContractTest::LEGACY_NAMES`, `LEGACY_OPTIONS`) are **append-only**:
  removing one silently breaks someone else's pipeline.
- **No prompt for a required argument** — only `init` asks questions
  (`GrammarMigrationTest::testNoCommandAnswersItsOwnRequiredArgument`).
- **Environment variables in tests** — set them through
  `tests/EnvGuardTrait.php`, never `putenv()` alone. `composer test` must stay
  green with `WPC_ACCENT_COLOR`, `WPC_NO_TUI`, `WPC_NO_DEPRECATED_WARNING` and
  `WPC_REPO_URL` exported (section *Architecture → tests/*).
- **TUI** — section *Architecture → src/Tui/*: render callbacks are
  `function`, never `fn`; the accent colour is only ever a background; frame
  assertions derive escape sequences rather than hard-coding them.

## Commits and sign-off (DCO)

This project uses the [Developer Certificate of Origin](https://developercertificate.org/)
(DCO) instead of a contributor licence agreement. By signing off a commit you
certify that you wrote it, or otherwise have the right to submit it under the
project's [MIT licence](LICENSE).

Sign off every commit with `-s`:

```bash
git commit -s -m "Fix the page count of an empty list"
```

which adds a trailer with your name and email:

```
Signed-off-by: Jane Doe <jane@example.com>
```

The name and email must match the commit author. A DCO check runs on every
pull request; to fix a branch that is missing sign-offs:

```bash
git rebase --signoff main
git push --force-with-lease
```

Keep commits focused, and write the message in the imperative ("Add",
"Fix"…) explaining *why* as well as what.

## Pull requests

- Branch from `main` and open the pull request against `main`.
- Add or update tests for the behaviour you change.
- Add an entry to [CHANGELOG.md](CHANGELOG.md) under the unreleased version for
  anything a user would notice (format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)).
- Update the README and CLAUDE.md when behaviour or architecture changes.
- Fill in the pull request checklist.

## Releases (maintainers)

Releases are cut by pushing a `vX.Y.Z` tag (pre-releases `vX.Y.Z-beta.1`,
`vX.Y.Z-rc.1`…). The release workflow builds and attests the phar, creates
the GitHub Release, and then waits for approval in the `release` environment
before publishing the update channel and the Docker images. Only a stable tag
moves `latest`. See `.github/workflows/release.yml`.

## Code of conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md). By
taking part you agree to abide by it.
