# Dual core setup

This extension supports TYPO3 v13 and v14 from one code base. Only one of the
two dependency sets can be installed in `.Build/` at a time, which makes the
following rule the most important one in the whole development workflow.

## The rule

> **The dependency set installed in `.Build/` must match the core version the
> tool is run for.**

`-t <version>` selects the core version for a suite, but it does **not** install
anything. Only `composerUpdate` changes what is in `.Build/`:

```bash
# 1. Install the dependency set ...
Build/Scripts/runTests.sh -t 13 -s composerUpdate

# 2. ... then run gates with the SAME -t value.
Build/Scripts/runTests.sh -t 13 -s phpstan
Build/Scripts/runTests.sh -t 13 -s unit
Build/Scripts/runTests.sh -t 13 -s functional -d sqlite
```

Running a suite with `-t 14` while the v13 dependency set is installed produces
**false positives and false negatives**, not an error message. PHPStan will
report missing classes that exist in the other core version, and tests will pass
or fail for reasons unrelated to the change under test.

`composerInstall` does **not** honour `-t`; it only replays the current
`composer.lock`. `composerUpdate` removes and reinstalls `.Build/` and
`composer.lock` — both are git-ignored, so nothing of value is lost.

Locally it drops the composer download cache in `.cache/` as well, which is why
a switch downloads the dependency set rather than unpacking it. That is a
deliberate precaution: switching core versions also switches the major version
of `typo3/class-alias-loader`, and an install is never left to resolve against
a cache belonging to the other major.
→ [The composer cache](quality-gates.md#continuous-integration)

## The changelogs come with the dependency set

The TYPO3 changelogs live inside the core package, so what is readable below
`.Build/vendor/typo3/cms-core/Documentation/Changelog/` depends on which set is
installed. A package carries the changelogs of its own and all **earlier**
versions: with v14 installed both `13.*` and `14.*` are there, with v13
installed there is no `14.0/`.

Installing v14 therefore gives the complete set for looking things up. Reading a
changelog is not running a gate — switch back before running one.
→ [Referencing TYPO3 behaviour changes](../workflow/commit-messages.md#referencing-typo3-behaviour-changes)

## Verifying a change

A change is only verified when the full sequence has run for **both** core
versions:

```bash
for core in 13 14; do
  Build/Scripts/runTests.sh -t "$core" -s composerUpdate
  Build/Scripts/runTests.sh -t "$core" -s cgl -n
  Build/Scripts/runTests.sh -t "$core" -s phpstan
  Build/Scripts/runTests.sh -t "$core" -s lintPhp
  Build/Scripts/runTests.sh -t "$core" -s unit
  Build/Scripts/runTests.sh -t "$core" -s unitRandom
  Build/Scripts/runTests.sh -t "$core" -s functional -d sqlite
  Build/Scripts/runTests.sh -t "$core" -s composerValidate
  Build/Scripts/runTests.sh -t "$core" -s checkBom
  Build/Scripts/runTests.sh -t "$core" -s checkExceptionCodes
  Build/Scripts/runTests.sh -t "$core" -s checkMarkdownTables
  Build/Scripts/runTests.sh -t "$core" -s checkTestMethodsPrefix
done
```

Add `-s functional -d mariadb -i 10.6` (or `mysql`, `postgres`) when the change
touches queries, schema or TCA — the schema is derived from TCA, so a TCA change
is a schema change.

Leaving the working copy on one core version and only running the other in CI is
not enough — CI reports the failure *after* the pull request is open, and the
core version aware code is exactly where mistakes happen.

## What is core version dependent

| Artefact                              | Per core version?                                  |
|---------------------------------------|----------------------------------------------------|
| `Classes/`                            | No — must work on all supported versions.          |
| `Core13/`, `Core14/`                  | Yes — one directory per core version.              |
| `Build/phpstan/Core13/`, `Core14/`    | Yes — separate config and baseline each.           |
| `Tests/Unit/Core13/`, `Core14/`       | Yes — grouped with `#[Group('not-core-<other>')]`. |
| `Tests/Functional/Core13/`, `Core14/` | Yes — same grouping.                               |
| `Build/phpunit/*.xml`                 | No — one configuration for both.                   |
| `.github/workflows/ci.yml`            | No — the core version is a matrix dimension.       |

### `Core13/` and `Core14/` carry a `.gitkeep`, and it is load bearing

Both directories hold nothing but a `.gitkeep` at the moment: this extension has
no version specific implementation yet. **Do not delete them for being empty.**
Two gates take the directory paths without checking whether they exist first:

- [`Build/phpstan/Core13/phpstan.neon`](../../Build/phpstan/Core13/phpstan.neon)
  lists `../../../Core13` under `paths`, and PHPStan aborts at startup on a
  path that is not there.
- [`Build/php-cs-fixer/config.php`](../../Build/php-cs-fixer/config.php) passes
  both directories to Symfony's `Finder::in()`, which throws a
  `DirectoryNotFoundException`.

Both are satisfied by the directory existing, which is all a `.gitkeep` buys —
git does not carry an empty directory on its own.
[`Configuration/Services.php`](../../Configuration/Services.php) guards with
`is_dir()` and is unaffected either way, and composer does not validate a PSR-4
target directory at all.

## Test grouping

`Build/Scripts/runTests.sh` passes `--exclude-group not-core-<version>` to
PHPUnit. A test that must not run on TYPO3 v14 therefore declares:

```php
#[Group('not-core-14')]
#[Test]
public function runsAgainstTheLowestSupportedMajorVersion(): void
{
}
```

Note the inverted logic: the group names the core version the test must **not**
run on, so a test without any group runs everywhere.

The attribute works on a class as well as on a method, and the two live
examples are methods:
[`Tests/ExtensionCoreVersionCompatTestsTrait.php`](../../Tests/ExtensionCoreVersionCompatTestsTrait.php)
declares one for each bound of the supported range, which is what proves a
suite ran against the core version it was asked for. The class level form
belongs on a test whose whole subject only exists on one version, and the
`Core13/` and `Core14/` test directories in the table above are where such a
class goes. Neither directory exists at the moment — nothing has needed one
yet, and an empty directory is not something git carries.

## See also

- [Development environment](environment.md)
- [Quality gates](quality-gates.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
