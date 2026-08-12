# Site based tests

Site based tests set up a real site configuration with several languages and
issue frontend sub-requests against it, so rendering, routing and language
resolution can be asserted end to end.

[`Tests/Functional/AbstractProfileTestCase.php`](../../Tests/Functional/AbstractProfileTestCase.php)
is the worked example: one page tree, one site, two languages.
[`Tests/Functional/Frontend/AbstractProfilePluginTestCase.php`](../../Tests/Functional/Frontend/AbstractProfilePluginTestCase.php)
adds the TypoScript and the request helpers on top of it, and the plugin tests
below it — [`ProfileListPluginTest`](../../Tests/Functional/Frontend/ProfileListPluginTest.php),
[`ProfileShowPluginTest`](../../Tests/Functional/Frontend/ProfileShowPluginTest.php),
[`ProfileEditPluginTest`](../../Tests/Functional/Frontend/ProfileEditPluginTest.php)
and [`ProfilePluginSiteSetTest`](../../Tests/Functional/Frontend/ProfilePluginSiteSetTest.php)
— assert rendered output.

## Why a package instead of the core trait

TYPO3 ships `SiteBasedTestTrait` inside the core mono-repository only. It lives
in a test namespace which is stripped from the distributed system extension
packages, so an extension cannot use it without installing `typo3/cms-core`
from source *and* registering that namespace in its own `composer.json` — easy
to get wrong, and it breaks whenever the core moves the file.

[`sbuerk/typo3-site-based-test-trait`](https://github.com/sbuerk/typo3-site-based-test-trait)
provides an equivalent trait as a normal package, and hides the differences the
core version introduced over time behind one API.

Its majors are pinned to a core version, so the constraint names both:

```json
"sbuerk/typo3-site-based-test-trait": "^2.0.1 || ^3.0.0"
```

| Package major | TYPO3 |
|---------------|-------|
| `2.x`         | v13   |
| `3.x`         | v14   |

`composerUpdate` resolves the major matching the `-t` core version, which is one
more reason why the installed dependency set must match the version a suite is
run for — see [Dual core setup](../development/dual-core-setup.md).

Beyond availability, the package differs from the core trait in ways that matter
for a test suite: a language that cannot be resolved **fails** the test instead
of silently marking it skipped, the annotations survive PHPStan level 8 without
a baseline entry, and `writeSiteConfiguration()` and `buildSiteConfiguration()`
take an additional array argument for site configuration keys the core trait has
no parameter for.

## No test extends the framework test case directly

[`AbstractFunctionalTestCase`](../../Tests/Functional/AbstractFunctionalTestCase.php)
extends the `FunctionalTestCase` of that package rather than the one of
`typo3/testing-framework`:

```php
use SBUERK\TYPO3\Testing\TestCase\FunctionalTestCase;

abstract class AbstractFunctionalTestCase extends FunctionalTestCase
```

The package class extends the framework class, so nothing is lost, and every
functional test gains its additions — most notably a `setUpFrontendRootPage()`
whose fourth argument can set up a root page *without* creating a
`sys_template` record, which is what site set based TypoScript needs.

Since every functional test already extends `AbstractFunctionalTestCase`, the
whole chain roots in the package class through that one edit. When adding an
intermediate abstract test case, keep it — just make sure the chain ends there
and not at the framework class.

## The three parts of a site based test

### 1. The language presets

`LANGUAGE_PRESETS` is what the identifiers passed to the build methods resolve
against:

```php
protected const LANGUAGE_PRESETS = [
    'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
    'DE' => ['id' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF8'],
];
```

A preset may carry a `custom` key, whose content is merged into the language
block of the written site configuration. That is how an extension adding its own
fields to a site language tests them.

### 2. The site configuration

```php
$this->writeSiteConfiguration(
    'acme',
    $this->buildSiteConfiguration(
        rootPageId: 1,
        base: 'https://acme.com/',
        websiteTitle: 'ACME',
    ),
    [
        $this->buildDefaultLanguageConfiguration(
            identifier: 'EN',
            base: 'https://acme.com/',
        ),
        $this->buildLanguageConfiguration(
            identifier: 'DE',
            base: 'https://acme.com/de/',
            fallbackIdentifiers: ['EN'],
            fallbackType: 'strict',
        ),
    ],
);
```

`fallbackType: 'strict'` is the interesting choice for a translation test: a
content element without a translation is **not** rendered from the default
language, so a test asserting translated output cannot pass by accident.

Site sets and other root level configuration go into the `additional` argument
rather than into a `dependencies` argument, which keeps the call identical
across package majors:

```php
$this->writeSiteConfiguration(
    identifier: 'acme',
    site: [],
    languages: [],
    errorHandling: [],
    additional: [
        'dependencies' => ['my-vendor/site-set-identifier'],
    ],
);
```

### 3. The page tree

The tree is imported from CSV data sets —
[`ProfileSite.csv`](../../Tests/Functional/Fixtures/Database/ProfileSite.csv)
for the two root pages, and
[`ProfilePlugins.csv`](../../Tests/Functional/Fixtures/Database/ProfilePlugins.csv)
for the pages the plugins sit on:

| uid | pid | language | `l10n_parent` | slug            |
|-----|-----|----------|---------------|-----------------|
| 1   | 0   | EN (0)   | –             | `/`             |
| 2   | 0   | DE (1)   | 1             | `/`             |
| 3   | 1   | EN (0)   | –             | `/profiles`     |
| 4   | 1   | EN (0)   | –             | `/edit-profile` |
| 5   | 1   | EN (0)   | –             | `/elsewhere`    |

Two things are worth copying from it:

- **Translations of a page keep the `pid` of the original**, they are not
  children of it. The relation is `l10n_parent` plus `sys_language_uid` — page 2
  above is the translation of page 1 and sits next to it, not below it.
- **Slugs are translated.** The language base is prepended by the router, so a
  German `/hallo` below a `https://acme.com/de/` base is reachable as
  `https://acme.com/de/hallo`. This fixture does not exercise that: the only
  translated page in it is the root page, whose slug is `/` in either language,
  and no subpage is translated. A fixture that reuses the default language slug
  in every language would never notice a routing bug, so a test about routing
  has to translate them.

The `tt_content` records placing the plugins are in `ProfilePlugins.csv` as
well, and none of them is translated. When one is, the pointer at the default
language record is `l18n_parent` — note the `l18n` spelling, `tt_content`
differs from `pages` here.

Finally the root page needs TypoScript, which is where the base test case comes
in:

```php
$this->setUpFrontendRootPage(
    self::STORAGE_PAGE_ID,
    [],
    ['config' => 'config.tx_extbase.persistence.storagePid = ' . self::STORAGE_PAGE_ID . LF],
);
```

The second argument takes TypoScript **files**, as
`['setup' => ['EXT:my_extension/Configuration/TypoScript/setup.typoscript']]`;
the third takes the fields of the generated `sys_template` record directly,
which is what the call above uses and what
[`AbstractProfilePluginTestCase`](../../Tests/Functional/Frontend/AbstractProfilePluginTestCase.php)
extends with an `include_static_file` and the plugin constants. Its second
flavour writes no `sys_template` record at all and passes `false` as the fourth
argument, because a site set based site gets its TypoScript from the site
configuration instead. Which of the two a test uses is one overridable setup
method, so the fixtures and the request helpers are shared —
`ProfilePluginSiteSetTest` overrides nothing else.

## The request

```php
$response = $this->executeFrontendSubRequest(new InternalRequest('https://acme.com/'), $context);

$this->assertSame(200, $response->getStatusCode());
$this->assertStringContainsString('<h2>Profiles</h2>', (string)$response->getBody());
```

The sub-request runs the real frontend in the same process — routing, TypoScript,
Extbase and Fluid included. Asserting the status code alone proves little; assert
on rendered content.

The `InternalRequestContext` is the second half of the request. It carries what
cannot be expressed in a URL — the frontend user id a session is simulated for,
and the workspace id — and `renderUri()` of
[`AbstractProfilePluginTestCase`](../../Tests/Functional/Frontend/AbstractProfilePluginTestCase.php)
is where the profile suite assembles it. Passing no user id is not the same as
passing `0`: one is a visitor without a session, the other a session of user
`0`, and several assertions turn on the difference.

A language dependent test adds a data provider with one case per language, keyed
so a failure names the language:

```php
yield '1 DE -> the translated record only' => [
    'languageId' => 1,
    'expectedShortnames' => ['translated-de'],
];
```

That key is quoted from
[`LanguageOverlayTest`](../../Tests/Functional/Domain/Repository/LanguageOverlayTest.php)
rather than from a rendering test, and the distinction is a real gap: the site
written here has two languages, but every sub-request the suite issues asks for
the default one. Language resolution is therefore asserted **below** the
rendering, on the repository, through the built environment — see
[Environment state](environment-state.md). A test that asserts translated
*output* still has to be written, and it needs translated page slugs and
translated `tt_content` records in the fixture first.

## What renders the plugin

Nothing stands in for the subject here: what a sub-request renders is the plugin
registration of [`ext_localconf.php`](../../ext_localconf.php) and the `CType` of
[`Configuration/TCA/Overrides/tt_content.php`](../../Configuration/TCA/Overrides/tt_content.php),
exactly as an installation would use them. Three details of that registration
are worth knowing, all of them the reason it works on both core versions
unchanged:

- `ExtensionUtility::configurePlugin()` is called with
  `PLUGIN_TYPE_CONTENT_ELEMENT` explicitly, and it has to be. TYPO3 v13 still
  defaults to `list_type` and **triggers a deprecation** for it; v14 removed
  that plugin content element, no longer defines the constant, and throws an
  `\InvalidArgumentException` for anything but `CType`. Naming `CType` is the
  one call correct on both. Omitting it would not fail silently either: the
  deprecation turns the v13 run red, because [the suites fail on
  deprecations](phpunit-configuration.md#strictness-policy). `ext_localconf.php`
  carries the file and line of both versions next to the call.
- `Configuration/TCA/Overrides/tt_content.php` passes **no** plugin type:
  `ExtensionUtility::registerPlugin()` reads it back on v13 from what
  `configurePlugin()` registered, and on v14 the parameter is gone. The order
  that makes the v13 lookup succeed is the natural one — `ext_localconf.php` is
  loaded before the TCA overrides.
- The generated rendering definition is `=< lib.contentElement`, which comes from
  EXT:fluid_styled_content and from nothing in `cms-core`, `cms-frontend` or
  `cms-extbase`. That is why this extension requires it, and why
  `AbstractProfilePluginTestCase` loads it into the test instance rather than
  defining a substitute `lib.contentElement` in test TypoScript: the substitute
  would make the tests green while removing the piece of the chain they exist to
  cover.

## See also

- [Functional tests](functional-tests.md)
- [Fixture extensions](fixture-extensions.md)
- [Environment state](environment-state.md)
- [Dual core setup](../development/dual-core-setup.md)
