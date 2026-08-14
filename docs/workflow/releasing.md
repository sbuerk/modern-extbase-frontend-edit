# Releasing

Two scripts in [`Build/Scripts/`](../../Build/Scripts) drive the release. Both
always operate on the repository root, no matter from where they are called, and
both show all options with `--help`.

## `setVersion.sh` — apply a version

Applies a version and its derived variants to every file carrying one: the
`COMPOSER_ROOT_VERSION` in `Build/Scripts/runTests.sh`, `extra.typo3/cms.version`
and `extra.branch-alias` in `composer.json`, the `version` in `ext_emconf.php`,
the `VERSION` file and — discovered dynamically, none has to exist — the
functional test [fixture extensions](../testing/fixture-extensions.md) below
`Tests/Functional/Fixtures/Extensions/`.

```bash
# Release version 1.2.0 (X.Y.Z, no branch-alias update).
Build/Scripts/setVersion.sh 1.2.0 release

# Next development version after it (X.Y.W-dev, branch-alias X.Y.x-dev).
Build/Scripts/setVersion.sh 1.2.1 post-release

# Force a plain development version, for example when branching.
Build/Scripts/setVersion.sh 1.3.0 dev

# Show every change without touching a file.
Build/Scripts/setVersion.sh 1.2.0 release --dry-run
```

The script only edits working-tree files; it performs no git or network
operations.

It reads and writes `composer.json` with **php**, not with `jq`, so it can also
be run through the container wrapper on a host that has neither — the testing
images ship `git` but no `jq`:

```bash
Build/Scripts/runTests.sh -s setVersion -- 1.2.0 release
```

Everything after `--` is passed to the script unchanged, `--dry-run` included.
Both ways produce the same result — the wrapper only adds the container.

## `release.sh` — orchestrate the release

Drives the full two-phase workflow for one release version: branch, apply the
release version, commit `[RELEASE] X.Y.Z`, push, open a pull request, wait for
the checks, merge, tag and push the tag — and afterwards the same for the next
development version with `[TASK] Set version X.Y.W`.

It has two independent safety gates:

```bash
# Print the whole plan, change nothing at all.
Build/Scripts/release.sh 1.2.0 --dry-run

# Run the local steps for real, but only PRINT every remote operation.
Build/Scripts/release.sh 1.2.0

# Actually publish: push, pull request, merge, tag.
Build/Scripts/release.sh 1.2.0 --execute
```

Without `--execute` no push, no pull request, no merge and no tag ever happens,
so a release can safely be rehearsed. `git` and the GitHub CLI (`gh`) have to be
available and authenticated for `--execute`.

Pushing the tag triggers the [`publish`](../../.github/workflows/publish.yml)
workflow, which builds the TER artifact, creates the GitHub release with that
artifact attached, and uploads the same file to the
[TYPO3 Extension Repository](https://extensions.typo3.org/extension/modern_extbase_frontend_edit).

The TER upload is the last step, and it is the only irreversible one: a version
number can be published to the TER exactly once. Everything before it can be
re-run — creating a release that exists only uploads its files again — so a
failed run is repeated from the top, and only the TER step can then object,
naming the version it already has.

## What the artifact contains

`tailor create-artefact` zips the **working tree**, and a CI checkout is the
whole repository: [`docs/`](../Index.md), the
[development site package](../development/dev-site-package.md) and the agent
instructions included. That is 680 KiB of a 1.8 MiB archive that no installation
has a use for, and it would reach the people who install from the TER but not
the people who install with composer — the archive GitHub generates honours
`export-ignore`, and tailor knows nothing about it.

[`Build/tailor/ExcludeFromPackaging.php`](../../Build/tailor/ExcludeFromPackaging.php)
closes that gap. It reads the `export-ignore` declarations out of
[`.gitattributes`](../../.gitattributes) and adds them to tailor's own defaults,
which makes that file the single place deciding what ships. The result is
entry for entry what `git archive HEAD` produces — 165 files rather than 223 —
so both channels distribute the same package.

Tailor **replaces** its exclusion list with the file that the
`TYPO3_EXCLUDE_FROM_PACKAGING` environment variable names rather than merging
into it, which is why that file loads tailor's defaults and merges them itself.
They are deliberately not copied: the workflow installs tailor unpinned, and the
list moves — 2.0.0 and the current development version already differ by a dozen
entries.

## Before releasing

- Both core versions green across the full [gate matrix](../development/quality-gates.md).
- Changelog entries for the version in place, see
  [Changelog and documentation](changelog-and-documentation.md).
- `Build/Scripts/runTests.sh -s renderDocumentation` passing.
- After the tag is pushed, **read the `Create local TER package upload artifact`
  step before the TER step spends the version number.** No gate covers
  `ExcludeFromPackaging.php`: it is verified by having been run once against the
  real tool, which proves that it worked, not that a broken one would be
  noticed. Attaching the wrong archive to a GitHub release is a mistake that can
  be corrected; publishing it to the TER is not.

## See also

- [Pull requests](pull-requests.md)
- [Commit messages](commit-messages.md)
