# Fixture extensions

A *fixture extension* is a minimal TYPO3 extension that exists only inside
[`Tests/Functional/Fixtures/Extensions/`](../../Tests/Functional/Fixtures/Extensions)
and is loaded by functional tests to provide test doubles, additional TCA,
service overrides or a plugin to render.

One is shipped, `workspace-fixture`. It puts a frontend sub-request into the
workspace a test asked for, which is what makes the workspace guard testable at
all, and it is the starting point for further ones.

## Why load them by composer package name

`typo3/testing-framework` resolves the entries of `$testExtensionsToLoad`
through its `ComposerPackageManager`, which only knows packages composer has
installed. A fixture extension is not installed — it lives inside the test
directory — so without help it can only be referenced by a path relative to the
repository root:

```php
protected array $testExtensionsToLoad = [
    'Tests/Functional/Fixtures/Extensions/workspace-fixture',
];
```

Paths are brittle: moving the fixture breaks every test naming it, and the
autoload configuration of the fixture still has to be registered by hand
somewhere. The [`sbuerk/fixture-packages`](https://github.com/sbuerk/fixture-packages)
composer plugin removes both problems, and the entry becomes the identifier the
extension itself declares:

```php
protected array $testExtensionsToLoad = [
    'tests/workspace-fixture',
];
```

## How it is wired

Three pieces, all of them already in place:

**1. The plugin is a development dependency and is allowed to run** — in
[`composer.json`](../../composer.json):

```json
"require-dev": {
    "sbuerk/fixture-packages": "^1.1.3"
},
"config": {
    "allow-plugins": {
        "sbuerk/fixture-packages": true
    }
}
```

**2. The paths to scan are configured** in the `extra` section of the same file:

```json
"extra": {
    "sbuerk/fixture-packages": {
        "paths": {
            "Tests/Functional/Fixtures/Extensions/*": [
                "autoload"
            ]
        }
    }
}
```

Every directory below that path containing a `composer.json` is picked up. Its
`autoload` section is adopted into the **`autoload-dev`** section of the root
package, which is what makes the fixture classes autoloadable in tests without
being autoloadable in a production installation. The plugin does this while
dumping the autoloader, so a newly added fixture extension becomes available
with:

```bash
Build/Scripts/runTests.sh -s composer -- dump-autoload
```

It also generates `.Build/vendor/sbuerk/AvailableFixturePackages.php`.

**3. The generated class is handed to the testing framework** in
[`Build/phpunit/FunctionalTestsBootstrap.php`](../../Build/phpunit/FunctionalTestsBootstrap.php):

```php
if (class_exists(AvailableFixturePackages::class)) {
    (new AvailableFixturePackages())->adoptFixtureExtensions();
}
```

`adoptFixtureExtensions()` registers each fixture extension with the
`ComposerPackageManager`, which is what allows both the composer package name
and the extension key to be used in `$testExtensionsToLoad`. The `class_exists()`
guard keeps the bootstrap working when the plugin is not installed, for example
in a `--no-dev` installation.

This is a deviation from the testing-framework boilerplate and is recorded as
such — see
[PHPUnit configuration](phpunit-configuration.md#deliberate-deviations-from-the-template).

## Layout of a fixture extension

```
Tests/Functional/Fixtures/Extensions/workspace-fixture/
├── composer.json
├── Classes/
│   └── Middleware/
│       └── WorkspaceAspectFromTestingContext.php
└── Configuration/
    ├── RequestMiddlewares.php
    └── Services.php
```

A fixture extension is a normal extension: `ext_localconf.php`, `ext_tables.sql`,
the TCA and — as here — `Configuration/RequestMiddlewares.php` are read in the
test instance exactly as they would be in a real installation. A fixture can
therefore carry a table with an Extbase model and repository of its own, a
plugin to render, or a service that replaces one the container would otherwise
resolve — everything a real extension can do, without any of it reaching a
production installation.

This one does none of that. It registers a single frontend middleware,
[`WorkspaceAspectFromTestingContext`](../../Tests/Functional/Fixtures/Extensions/workspace-fixture/Classes/Middleware/WorkspaceAspectFromTestingContext.php),
which sets the `workspace` aspect of the shared `Context` from the workspace id
the test passed in its `InternalRequestContext` — and nothing else. Its docblock
states why the handling of the testing framework itself cannot do that here, and
what the middleware does **not** simulate. A fixture that stands in for a
mechanism has to say where the stand-in ends, or a test will eventually claim
the missing part.

A fixture that does carry a table has one thing worth knowing about the table
name: Extbase derives it from the **class name of the model**, not from the
extension key. This extension is the illustration —
`SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile` becomes
`tx_modernextbasefrontendedit_domain_model_profile`, the vendor part dropped and
the rest lower cased, and the extension key `modern_extbase_frontend_edit` does
not appear in it.

The `ext_tables.sql` that goes with such a table declares nothing the TCA
already describes. TYPO3 derives the columns from it — the system fields and,
since Feature #101553, the business columns as well — and an explicit definition
always wins over the derived one, so a repeated `pid` or `sys_language_uid`
additionally suppresses the index that would have been generated with it. The
[`ext_tables.sql`](../../ext_tables.sql) of this extension is what that leaves:
the two columns whose derived definition differs between v13 and v14, and
nothing else.

Configuration of a fixture extension is held to the same rules as configuration
of the extension itself: it cannot use the `Core13/` and `Core14/` split,
because TYPO3 loads it from a fixed path, so a core version difference is
applied to the finished array before it is returned. See
[Core version aware code](../architecture/core-version-aware-code.md#configuration-is-the-exception).

The [`composer.json`](../../Tests/Functional/Fixtures/Extensions/workspace-fixture/composer.json)
is what turns the directory into a package the plugin can find. It needs a name,
the `typo3-cms-extension` type, an extension key, a `version` — nothing resolves
one for a package that is not installed — and the autoload configuration to be
adopted:

```json
{
    "name": "tests/workspace-fixture",
    "type": "typo3-cms-extension",
    "version": "1.0.0-dev",
    "autoload": {
        "psr-4": {
            "TESTS\\WorkspaceFixture\\": "Classes/"
        }
    },
    "extra": {
        "typo3/cms": {
            "extension-key": "tests_workspace_fixture"
        }
    }
}
```

No `ext_emconf.php` is needed: the test instance is built in composer mode.

[`Configuration/Services.php`](../../Tests/Functional/Fixtures/Extensions/workspace-fixture/Configuration/Services.php)
is deliberately generic and does nothing but register the classes of the
fixture, exactly as a real extension would. Services are wired with
[dependency injection attributes](../architecture/dependency-injection.md) on
the classes themselves, and the middleware is the one service here:

```php
#[Autoconfigure(public: true)]
final readonly class WorkspaceAspectFromTestingContext implements MiddlewareInterface
```

Publishing it is deliberate, and the class says why:
`MiddlewareDispatcher::lazy()` resolves the target through the container and
falls back to `GeneralUtility::makeInstance()` — without constructor injection
— when `ContainerInterface::has()` answers `false`. A framework constraint is
exactly the case the [rule that services are
private](../architecture/dependency-injection.md#rules) makes room for. A
fixture extension does not get to relax that rule; it states its exception like
any other code here.

A fixture extension is **not** core version aware. There is no `Core13/` and
`Core14/` split — if a fixture needs to behave differently per core version,
that belongs in the test, not in the fixture.

## What the test proves

[`Tests/Functional/FixturePackagesTest.php`](../../Tests/Functional/FixturePackagesTest.php)
has the wiring as its subject, not the fixture:

| Assertion                                               | What breaks without it                                |
|---------------------------------------------------------|-------------------------------------------------------|
| The extension is loaded under `tests/workspace-fixture` | The `adoptFixtureExtensions()` call in the bootstrap. |
| The extension is loaded under `tests_workspace_fixture` | The extension key registration.                       |

Both spellings are asserted because they resolve differently and only one of
them is exercised anywhere else: every other test loading a fixture extension
names it by its composer package name, so nothing but this test would notice if
resolution by extension key broke.

That the adopted `autoload` configuration works is not asserted here and does
not need to be. The two tests loading the fixture for its behaviour —
[`ProfileAjaxWorkspaceTest`](../../Tests/Functional/Frontend/ProfileAjaxWorkspaceTest.php)
and [`ProfileEditPluginTest`](../../Tests/Functional/Frontend/ProfileEditPluginTest.php)
— cannot pass unless the middleware class is found, and it is found only
through the `autoload` section the composer plugin adopted.

## Adding a fixture extension

1. Create the directory with a `composer.json` as above.
2. Run `Build/Scripts/runTests.sh -s composer -- dump-autoload` so the plugin
   picks it up.
3. Name it in `$testExtensionsToLoad` of the test that needs it. Redeclaring
   that property **replaces** the one of
   [`AbstractFunctionalTestCase`](../../Tests/Functional/AbstractFunctionalTestCase.php),
   so repeat the extension itself:

   ```php
   protected array $testExtensionsToLoad = [
       'sbuerk/modern-extbase-frontend-edit',
       'tests/workspace-fixture',
   ];
   ```

## See also

- [Functional tests](functional-tests.md)
- [PHPUnit configuration](phpunit-configuration.md)
- [Site based tests](site-based-tests.md)
- [Dependency injection](../architecture/dependency-injection.md)
