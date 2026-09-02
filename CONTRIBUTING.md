# Contributing to Yamandu Native AI Content Creator

Thank you for your interest in contributing to Yamandu.

## Before you start

Please open or review an existing issue before working on substantial changes. For small fixes, documentation improvements, and clearly scoped bug fixes, a direct pull request is also welcome.

Do not include API keys, credentials, private URLs, personal data, or other secrets in issues, commits, pull requests, screenshots, fixtures, or test data.

## Development guidelines

- Keep changes focused and as small as reasonably possible.
- Follow WordPress coding conventions and existing project patterns.
- Sanitize, validate, and escape data appropriately.
- Preserve capability checks, nonces, and authorization boundaries in administrative and AJAX flows.
- Avoid introducing unnecessary dependencies.
- Keep external requests explicit and consistent with the plugin's consent and privacy model.
- Maintain compatibility with the supported WordPress and PHP versions unless the change intentionally updates those requirements.
- Update documentation when behavior, settings, dependencies, privacy implications, or supported services change.

## Pull requests

A pull request should explain:

- what problem it solves;
- what changed;
- how the change was tested;
- whether it affects security, privacy, external APIs, stored data, or backward compatibility;
- whether documentation or translations need updating.

Please avoid combining unrelated changes in the same pull request.

## Bug reports

Useful bug reports include:

- Yamandu version;
- WordPress version;
- PHP version;
- active editor or relevant WordPress screen;
- steps to reproduce;
- expected behavior;
- actual behavior;
- relevant error messages or logs with sensitive data removed.

## Security issues

Do not report security vulnerabilities in public issues. Follow the instructions in `SECURITY.md`.

## Licensing

By contributing code or documentation to this repository, you agree that your contribution may be distributed under the repository's applicable license terms. Yamandu Native AI Content Creator is licensed under GPL-2.0-or-later. Third-party components retain their own licenses.
