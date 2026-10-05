<!--
Thanks for contributing! Please read CONTRIBUTING.md first.
Every commit must be signed off (`git commit -s`) under the Developer
Certificate of Origin: https://developercertificate.org/
-->

## What and why

<!-- What does this change, and what problem does it solve? Link the issue: "Fixes #123". -->

## How it was tested

<!-- Commands run, scenarios covered. For a TUI change, which terminal. -->

## Checklist

- [ ] Every commit is signed off (`Signed-off-by:`, from `git commit -s`) — the DCO check enforces it
- [ ] `composer test`, `composer analyse` and `composer cs` pass locally
- [ ] Tests added or updated for the change
- [ ] **CLI contract**: no command, alias, argument, option, output format, stream or exit code changed — or the change is intentional, `tests/fixtures/command-contract.json` is updated, and it is called out below
- [ ] No v1 name removed from `CommandContractTest::LEGACY_NAMES` / `LEGACY_OPTIONS` (append-only)
- [ ] `CHANGELOG.md` updated under the unreleased version (user-visible changes only)
- [ ] Docs updated if behaviour changed (`README.md`, and `CLAUDE.md` for architecture or contract notes)
- [ ] No API key, token or other secret in the code, tests, fixtures or logs

## Contract changes / notes for the reviewer

<!-- Anything breaking, surprising, or that needs a decision. -->
