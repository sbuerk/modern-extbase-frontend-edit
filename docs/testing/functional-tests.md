# Functional tests

Functional tests live below [`Tests/Functional/`](../../Tests/Functional). They
boot a real TYPO3 instance with a database, so the dependency injection
container, TCA, the persistence layer and — where needed — frontend rendering
are available.

## Running

```bash
# Functional tests on SQLite (no database container required).
Build/Scripts/runTests.sh -s functional -d sqlite

# Functional tests against other database management systems.
Build/Scripts/runTests.sh -s functional -d mariadb -i 10.6
Build/Scripts/runTests.sh -s functional -d mysql -i 8.0
Build/Scripts/runTests.sh -s functional -d postgres -i 10

# A single class or method.
Build/Scripts/runTests.sh -s functional -d sqlite -- --filter ExtensionLoadedTest
```

SQLite is the fastest option and enough for most work. Run at least one other
DBMS before opening a pull request when the change touches queries, schema or
**TCA** — TYPO3 derives the schema from the TCA, so a TCA change is a schema
change.

### Database versions

`-i` selects the image version, and `runTests.sh -h` lists which ones are
accepted per DBMS: MariaDB `10.4` … `11.8`, MySQL `8.0` … `8.4`, PostgreSQL
`10` … `18`. The default is the oldest still supported version, so a run without
`-i` tests the floor of the version range rather than the comfortable case.

PostgreSQL 18 moved its data directory and refuses to start when a mount sits at
the old location. The wrapper places the mount one level higher for `18` and
above, which is the mount point the image documents for that case — nothing to
configure, but it explains why that one version is special cased.

Remember to run both core versions, each after the matching `composerUpdate` —
see [Dual core setup](../development/dual-core-setup.md).

## The test that proves the instance boots

[`Tests/Functional/ExtensionLoadedTest`](../../Tests/Functional/ExtensionLoadedTest.php)
asserts little and is worth a lot: it cannot reach its assertions without
booting a complete TYPO3 instance with this extension installed, which compiles
the dependency injection container, executes the extension bootstrap, loads and
migrates the TCA and derives the database schema. An unresolvable service
argument or a TCA structure the other core version has migrated away fails
there rather than in whichever feature test touches it first.

It is **never removed** —
see [the two tests that must never be dropped](Index.md#the-two-tests-that-must-never-be-dropped).

## The base test case

Functional tests extend
[`Tests/Functional/AbstractFunctionalTestCase`](../../Tests/Functional/AbstractFunctionalTestCase.php),
never the testing framework class directly. It takes care of loading the
extension itself into the test instance:

```php
use SBUERK\TYPO3\Testing\TestCase\FunctionalTestCase;

abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/modern-extbase-frontend-edit',
    ];
}
```

The `FunctionalTestCase` it extends is the one of
`sbuerk/typo3-site-based-test-trait`, which extends the testing framework class
and adds what a site based test needs. Keeping that at the root of the chain is
a rule, not a detail — see
[Site based tests](site-based-tests.md#no-test-extends-the-framework-test-case-directly).

A test needing more extensions extends the abstract class and redeclares
`$testExtensionsToLoad`. Redeclaring **replaces** the property, so the extension
itself has to be repeated:

```php
protected array $testExtensionsToLoad = [
    'sbuerk/modern-extbase-frontend-edit',
    'tests/workspace-fixture',
];
```

Loading an extension by its **composer package name** rather than by a path
works for test-only extensions too — see
[Fixture extensions](fixture-extensions.md).

## Conventions

Same as for [unit tests](unit-tests.md): `final` classes, `#[Test]` attributes,
no `test` prefix, named data provider keys.

Additionally:

- Assert against the container through `$this->get()` when verifying wiring.
  `$this->getContainer()->has()` answers whether a service is registered at all,
  which is what a core version aware test uses to prove the *other* version's
  implementation is absent.
- Import records with `importCSVDataSet()` or a DataHandler scenario rather than
  writing SQL.
- Wrap expensive fixture setup in `withDatabaseSnapshot()` so it is built once
  and restored per test.

## Core version aware functional tests

Mirroring the source layout, they belong in `Tests/Functional/Core13/` and
`Tests/Functional/Core14/` and carry the group of the core version they must
**not** run on. A test for a v13 only implementation would read:

```php
#[Group('not-core-14')]
final class SomeServiceTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theInterfaceIsAliasedToTheImplementationOfThisCoreVersion(): void
    {
        $this->assertInstanceOf(SomeService::class, $this->get(SomeServiceInterface::class));
    }
}
```

Neither directory exists at the moment, and that is not an omission: nothing
below `Classes/` has needed a version specific implementation yet, so there is
nothing for such a test to assert. The directory is created together with the
first test that goes into it.

See [Dual core setup](../development/dual-core-setup.md#test-grouping).

### A test can be grouped because the core differs

The same groups also apply to a test that lives in the normal tree and asserts
shared code, when the **core** makes the case unreachable on one version. The
image upload is the one instance in this repository: TYPO3 v13 refuses a
constructed `UploadedFile` in `ResourceStorage`, so only a test that expects the
upload to *succeed* is excluded there, per method rather than per class:

```php
#[Group(self::UPLOAD_CANNOT_BE_SIMULATED_ON_CORE_13)]
#[Test]
public function anUploadStoresTheFileAndAnswersWithTheNewImage(): void
```

Two rules keep that honest, and both matter more than the mechanism:

- **Group the method, never the class.** Everything the class asserts about
  refusals runs on both core versions — those are the assertions a security
  review reads.
- **Say why at the group, with the citations.** The constant above carries the
  core difference by `path:line` for both versions, the statement that
  production is unaffected, and where the case is covered instead. A group whose
  reason is not written down reads as "flaky on v13" within a release.

A PHPUnit group excludes a **method**, not a data set. A provider with one row
that is v14-only therefore splits: the rows that run everywhere stay in the data
provider driven test, the single row becomes a test of its own —
`ProfileAjaxWorkspaceTest` does exactly that for its live workspace control case.

→ [Image handling](../frontend-edit/image-handling.md#a-successful-upload-can-only-be-simulated-on-v14)

## Strictness

Notices, warnings and deprecations fail the suite, and so do a test without an
assertion, a test writing to the output and an incomplete test. This is
deliberate — see
[PHPUnit configuration](phpunit-configuration.md#strictness-policy). Do not
silence them in the test; fix the code that triggers them.

Debug output is the one to watch for here: a `var_dump()` left in a test or in
the code under test turns a green functional test red.

## The mysql job in CI is slow, not broken

This suite has one long standing reputation problem, and measuring it changed
what it is. `functional mysql 8.0` was recorded here for a long time as
*flaky* — a database dying part way through a run. Over the retained CI history
that is not what it does.

**847 functional jobs, 0 failures.** 808 succeeded, 37 were cancelled by the
`concurrency` group superseding a run, 2 were skipped. The originally recorded
failure predates GitHub's log retention and could not be retrieved, so it is
neither confirmed nor denied here; what is measurable is a different phenomenon
with the same job name.

What the job actually does is take between one and six times as long as itself,
and job duration tells the story better than any log:

| Suite          | n   | median | p90   | max       | jobs > 2× median |
|----------------|-----|--------|-------|-----------|------------------|
| `sqlite`       | 168 | 81 s   | 94 s  | 106 s     | **0**            |
| `mariadb 10.6` | 160 | 115 s  | 159 s | 359 s     | 3                |
| `mariadb 10.4` | 160 | 128 s  | 200 s | 561 s     | 9                |
| `mysql 8.0`    | 160 | 167 s  | 251 s | **914 s** | **12**           |
| `postgres 10`  | 160 | 186 s  | 210 s | 224 s     | **0**            |

The row that settles it is `postgres`. It has the **highest median of all five**
and produces **no outliers at all**, so "the slowest suite gets the worst tail"
is not the explanation. Something is specific to the MySQL path.

**It is bound to the runner host, and this repository is not the cause.** Every
job log carries `Azure Region:`, and grouping by it separates cleanly:

| Region      | `mysql` median                       | `postgres` median | `sqlite` median |
|-------------|--------------------------------------|-------------------|-----------------|
| `centralus` | **290 s** (1.73×), 7 of 16 beyond 2× | 172 s (0.92×), 0  | 77 s (0.95×), 0 |
| every other | 160–176 s (≈1.0×)                    | 178–195 s, 0      | 77–89 s, 0      |

`centralus` is, if anything, a slightly **faster** region for the other two
suites. It is only mysql that degrades there.

Two further checks rule out the obvious alternatives. All **808 successful jobs
ran on 808 distinct runners**, so the four sibling matrix jobs never share a
machine. And a slow VM is not slow at anything else — comparing two jobs of the
**same CI run**, one slow and one fast:

| Step                     | slow job | fast sibling | ratio    |
|--------------------------|----------|--------------|----------|
| Execute functional tests | 809 s    | 114 s        | **7.1×** |
| Prepare dependencies     | 17 s     | 10 s         | 1.7×     |
| Checkout                 | 2 s      | 2 s          | 1.0×     |
| Set up job               | 1 s      | 1 s          | 1.0×     |

`Prepare dependencies` is a full composer install — network, disk and CPU. It
barely moves while the database workload stretches sevenfold, so whatever the
host is short of, it is not general throughput.

Two consequences for working here:

- **A slow mysql job is not a signal about the change under review.** Do not
  re-run it hoping for a different result, and do not read a 9 minute job as a
  hint that something was made slower.
- **It is not solved, and the next experiment is named.** What would settle the
  mechanism is one CI step printing `/proc/pressure/{io,memory,cpu}`, `nproc`,
  `free -m` and the clocksource before and after the suite, collected over a few
  dozen runs and correlated against the region. That has not been added, because
  the phenomenon costs waiting rather than correctness and a permanent probe is
  a permanent cost.

`runTests.sh` starts mysql with `--skip-log-bin --performance-schema=OFF`, which
came out of this investigation but is **not** a fix for it. Both are defaults
MariaDB has the other way round, so every comparison between the two started with
mysql doing two things mariadb was not; switching them off removes a confounder.
It was measured and does **not** make the suite meaningfully faster — 112 s
against 110 s locally, inside the noise.

## Related topics

| Topic                                               | Page                                        |
|-----------------------------------------------------|---------------------------------------------|
| Loading fixture extensions by composer package name | [Fixture extensions](fixture-extensions.md) |
| Site configuration and frontend requests            | [Site based tests](site-based-tests.md)     |
| Language and application context in tests           | [Environment state](environment-state.md)   |

## See also

- [PHPUnit configuration](phpunit-configuration.md)
- [Unit tests](unit-tests.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
