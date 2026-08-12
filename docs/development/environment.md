# Development environment

All tests and quality tools run in containers through the
[`Build/Scripts/runTests.sh`](../../Build/Scripts/runTests.sh) wrapper. The only
requirement on the host is a container runtime — **podman** (preferred) or
**docker**. The wrapper pulls the required TYPO3 testing images on first use;
neither PHP nor Composer needs to be installed on the host.

Dependencies are installed into the git-ignored `.Build/` directory. The
wrapper installs them for a specific TYPO3 core and PHP version:

```bash
# Install dependencies for TYPO3 v13 on PHP 8.2 (default matrix).
Build/Scripts/runTests.sh -t 13 -p 8.2 -s composerUpdate

# Switch the working copy to the TYPO3 v14 dependency set.
Build/Scripts/runTests.sh -t 14 -p 8.2 -s composerUpdate
```

> [!IMPORTANT]
> The installed dependency set must match the core version a gate is run for.
> See [Dual core setup](dual-core-setup.md) — this is the single most common
> source of false positives in this repository.

Run `Build/Scripts/runTests.sh -h` to see all suites and options.

The wrapper detects whether it is attached to a terminal. Interactively it runs
the containers with `-it`; from a pipe, a wrapper script, an IDE run
configuration or a git hook it drops those flags — podman would only warn, but
docker fails outright, and redirected output would carry TTY control characters.
`--init` is kept either way, so ctrl-c still reaches the process in the
container.

It picks **podman** whenever it is installed and falls back to docker; `-b`
overrides that. There is no reason to pass it locally — the workflows do, and
[why they do](quality-gates.md#why-ci-passes--b-docker) is a property of GitHub
hosted runners, not of this repository.

## Frequently used options

| Option         | Meaning                                                                   |
|----------------|---------------------------------------------------------------------------|
| `-s <suite>`   | Suite to run (`unit`, `functional`, `cgl`, `phpstan`, …).                 |
| `-t <13\|14>`  | TYPO3 core major version to run against. Default `13`.                    |
| `-p <version>` | PHP version (`8.2` … `8.5`). Default `8.2`.                               |
| `-d <dbms>`    | Database for functional tests (`sqlite`, `mariadb`, `mysql`, `postgres`). |
| `-i <version>` | Database image version, together with `-d`. `-h` lists the accepted ones. |
| `-b <bin>`     | Container binary, `podman` or `docker`. Auto-detected, podman preferred.  |
| `-n`           | Check only, do not modify files (`cgl` and `lintTypescript`, as in CI).   |
| `-o <seed>`    | Replay a specific random order seed with `unitRandom`.                    |
| `-h`           | Full help with every suite and option.                                    |

## Suites

| Suite                        | Purpose                                                                       |
|------------------------------|-------------------------------------------------------------------------------|
| `unit`                       | PHP unit tests (default suite).                                               |
| `unitRandom`                 | Unit tests in random order.                                                   |
| `functional`                 | PHP functional tests.                                                         |
| `cgl`                        | Coding guidelines, fix in place or check with `-n`.                           |
| `phpstan`                    | Static analysis.                                                              |
| `phpstanGenerateBaseline`    | Regenerate the PHPStan baseline of the selected core version.                 |
| `lintPhp`                    | PHP linting.                                                                  |
| `checkBom`                   | UTF-8 files must not contain a BOM.                                           |
| `checkExceptionCodes`        | Duplicate or missing exception codes.                                         |
| `checkMarkdownTables`        | Markdown tables must be formatted, `-- --fix` formats them.                   |
| `checkRstSectionAdornments`  | reST adornments must match their title, `-- --fix` adjusts them.              |
| `checkTestMethodsPrefix`     | Test methods must not start with `test`.                                      |
| `lintTypescript`             | eslint over every TypeScript tree, fixes in place, `-n` checks.               |
| `typecheckJs`                | `tsc --noEmit` over every TypeScript tree, which the asset build does not do. |
| `unitJs`                     | TypeScript unit tests, run with `node --test`.                                |
| `buildJs`                    | Compile `Build/Sources/` into `Resources/Public/`.                            |
| `checkJsBuildClean`          | The committed artifacts must match `Build/Sources/`.                          |
| `npm`                        | `npm` with all remaining arguments, run in `Build/`.                          |
| `composer`                   | `composer` with all remaining arguments dispatched.                           |
| `composerInstall`            | `composer install`.                                                           |
| `composerUpdate`             | `composer update` for the core version given with `-t`.                       |
| `composerValidate`           | `composer validate --strict` of the root `composer.json`.                     |
| `renderDocumentation`        | Render `Documentation/` into `Documentation-GENERATED-temp/`.                 |
| `setVersion`                 | Apply a version, `-- <version> <type>`.                                       |
| `watchDocumentation`         | Serve `Documentation/`, re-rendering on every change.                         |
| `clean`                      | Remove build, cache, rendered documentation and test files.                   |
| `cleanCache`                 | Cache files and folders only.                                                 |
| `cleanJs`                    | `Build/node_modules` and `Build/.cache` only.                                 |
| `cleanRenderedDocumentation` | `Documentation-GENERATED-temp/` only.                                         |
| `cleanTests`                 | Test related files and folders only.                                          |

The six node based suites — `lintTypescript`, `typecheckJs`, `unitJs`,
`buildJs`, `checkJsBuildClean` and `npm` — run in a node container and ignore
`-t` entirely. They read `Build/Sources/`, `Build/Tests/` and
`Resources/Public/`, never the installed core, and are the only suites that need
no `composerUpdate` first.
→ [Frontend assets](../frontend-edit/frontend-assets.md#the-runtestssh-suites)

## When a functional run says the database refused the connection

A functional run against `mariadb`, `mysql` or `postgres` starts the database in
its own container and waits for it before handing over to PHPUnit. Two things
about that wait are worth knowing, because both were learnt from failures that
looked like defects in the code under test.

The wait asks the server to **answer a query**, through the client shipped in
the database image itself, rather than checking that the port is open. An open
port is not a ready server: the mysql image runs a temporary server while it
initialises its data directory, and it accepts a connection there. The budget is
a minute, which is long enough for a first initialisation on a loaded machine.

If the suite fails and the database container is no longer running, the wrapper
says so and prints the last lines of that container's log, instead of leaving
several hundred connection errors to be interpreted. That is also why the
database containers are started **without** `--rm`: a container that removes
itself on exit takes its log with it, and the log is the only evidence of why it
stopped. `cleanUp()` removes them either way.

None of this makes a database that dies mid-run succeed. It makes the difference
between a run that fails with an explanation and a run that fails with a wall of
stack traces.

## Passing arguments to the underlying tool

The wrapper parses its own options with `getopts`, so arguments meant for
PHPUnit (or any other dispatched tool) must follow a `--` separator:

```bash
Build/Scripts/runTests.sh -s functional -d sqlite -- --filter ExtensionLoadedTest
```

## See also

- [Dual core setup](dual-core-setup.md)
- [Quality gates](quality-gates.md)
- [Frontend assets](../frontend-edit/frontend-assets.md)
- [Testing](../testing/Index.md)
