# Security policy

## Supported versions

Security fixes are released for the **latest minor version of 2.x** only.
`wpc self-update` (or `composer global update wpcontent/wpc-cli`, or pulling
the Docker image again) is how a fix reaches you.

| Version | Supported |
| --- | --- |
| Latest 2.x minor | Yes |
| Older 2.x minors | No — update to the latest minor |
| 1.x | No |

## Reporting a vulnerability

**Please do not open a public issue, discussion or pull request for a
security problem.**

Report it privately, by either:

- GitHub's private vulnerability reporting: the **"Report a vulnerability"**
  button under the repository's **Security** tab
  (<https://github.com/wp-content-io/wpc-cli/security/advisories/new>);
- or email to **bonjour@alexch.fr**.

Please include the `wpc --version` output, how `wpc` was installed (phar,
Docker image, Composer), the steps to reproduce, and the impact you see.
**Never include a real API key** — redact it, and revoke any key that was
exposed.

You can expect an acknowledgement within a few working days. Once a fix is
released, the advisory is published on GitHub and credits the reporter unless
they prefer otherwise.

## Scope

This repository is the `wpc` command-line client only: the phar, its
`self-update` mechanism, the Docker images and the release pipeline. Problems
with the wp-content.io service itself (the registry API, the dashboard) are
handled through the same channels, but are fixed outside this repository.

Areas of particular interest for the client:

- `self-update`: manifest pinning, checksum verification, redirect handling
  (`src/Update/`);
- handling of the API key (`--api-key`, `WPC_API_KEY`) — it must never be
  printed, logged or sent anywhere but the configured registry;
- archive building (`src/Builder/`): paths that escape the source directory.
