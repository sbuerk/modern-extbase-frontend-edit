# Unit tests

Unit tests live below [`Tests/Unit/`](../../Tests/Unit) and extend
`TYPO3\TestingFramework\Core\Unit\UnitTestCase`. They run without a database and
without a TYPO3 instance.

## Running

```bash
# Unit tests.
Build/Scripts/runTests.sh -s unit

# Unit tests in random order (add "-o <seed>" to replay a specific order).
Build/Scripts/runTests.sh -s unitRandom

# A single class or method.
Build/Scripts/runTests.sh -s unit -- --filter VersionCompatTest
```

`unitRandom` exists to catch tests that depend on execution order. When it fails,
the output contains the seed; replay it with `-o <seed>` to reproduce.

Remember to run both core versions, each after the matching `composerUpdate` —
see [Dual core setup](../development/dual-core-setup.md).

## Conventions

- Test classes are `final` and named `<SubjectUnderTest>Test`.
- Test methods carry the PHPUnit `#[Test]` attribute and must **not** be
  prefixed with `test` — enforced by the `checkTestMethodsPrefix` gate:

  ```php
  #[Test]
  public function bioDefaultsToAnEmptyStringAndIsNeverNullable(): void
  {
      // ...
  }
  ```

- Method names describe the expected behaviour, not the mechanics:
  `collectionsAreInitializedAndEmptyOnConstruction()`, not `testConstructor()`.
- Every test asserts something. A test without an assertion is risky and
  therefore a failure. When the behaviour under test is "this does not throw",
  say so with `self::expectNotToPerformAssertions()` instead of leaving the
  method bare — see
  [PHPUnit configuration](phpunit-configuration.md#strictness-policy).
- Nothing is written to the output. A leftover `var_dump()` or `echo` makes the
  test risky and fails the run.
- Data providers are `public static` and return a `\Generator` with named keys,
  so a failing case is identifiable in the output:

  ```php
  public static function expectedLoadedExtensionIdentifiers(): \Generator
  {
      yield 'composer package name: sbuerk/modern-extbase-frontend-edit' => ['identifier' => 'sbuerk/modern-extbase-frontend-edit'];
      yield 'extension key: modern_extbase_frontend_edit' => ['identifier' => 'modern_extbase_frontend_edit'];
  }
  ```

## Core version aware unit tests

Tests for classes below `Core13/` and `Core14/` mirror that layout in
`Tests/Unit/Core13/` and `Tests/Unit/Core14/`, and carry the group of the core
version they must **not** run on:

```php
#[Group('not-core-14')]
final class SomeServiceTest extends UnitTestCase
{
}
```

Neither directory exists at the moment, for the same reason `Core13/` and
`Core14/` hold nothing but a `.gitkeep`: no class below them yet. Both are
created together with the first implementation that needs them.

See [Dual core setup](../development/dual-core-setup.md#test-grouping).

The same grouping is what makes
[`Tests/Unit/VersionCompatTest`](../../Tests/Unit/VersionCompatTest.php) work:
it asserts that a run with `-t 13` really is v13 and one with `-t 14` really is
v14, so a stale `.Build/` cannot produce a green suite that proved nothing. That
test is **never removed** —
see [the two tests that must never be dropped](Index.md#the-two-tests-that-must-never-be-dropped).

## Testing classes with injected dependencies

A class using `#[Required]` method injection is constructed and injected by hand
in a unit test — there is no container:

```php
$subject = new SomeService();
$subject->injectTypo3Version(new Typo3Version());

$this->assertSame('expected', $subject->someMethod());
```

That sample is hypothetical, and deliberately so: no class in `Classes/` uses
method injection at the moment. The one abstract class there,
[`AbstractEditRepository`](../../Classes/Domain/Repository/Edit/AbstractEditRepository.php),
states in its docblock why it needs no `inject*()` method — an abstract class
must not use constructor injection, because its constructor is part of the API
of every extending class, and this one has no collaborators beyond those
`Repository` already receives that way. The pattern is what an abstract class
*with* dependencies uses, so how to test one is worth knowing before the first
appears — see [Class design](../architecture/class-design.md).

If wiring itself is what needs verification, that belongs in a
[functional test](functional-tests.md), where the real container is available.

## See also

- [PHPUnit configuration](phpunit-configuration.md)
- [Functional tests](functional-tests.md)
- [Class design](../architecture/class-design.md)
