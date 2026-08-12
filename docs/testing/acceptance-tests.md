# Acceptance tests

The third suite, and the only one with a running TYPO3 and a real browser in it.
It answers the questions the other two structurally cannot: does the custom
element upgrade, does `lit` resolve out of the frontend import map, does a save
actually reach the database, and is the profile still readable with JavaScript
switched off.

```bash
# The whole suite. SQLite only; no "-d" and no "-t 14 first" ceremony.
Build/Scripts/runTests.sh -s acceptance

# Playwright arguments after "--", the same convention as the PHPUnit suites.
Build/Scripts/runTests.sh -s acceptance -- --grep "cancel"
Build/Scripts/runTests.sh -s acceptance -- Tests/Acceptance/Frontend/InlineEdit.spec.ts
```

A full run takes about **12 seconds** on a warm machine, of which the specs
themselves are six. The first run additionally pulls two container images of
about 2 GB together.

## What lives where

| Path                                                                                           | Contents                                                               |
|------------------------------------------------------------------------------------------------|------------------------------------------------------------------------|
| [`Build/Scripts/setupAcceptanceInstance.php`](../../Build/Scripts/setupAcceptanceInstance.php) | Builds and seeds the instance, and writes the manifest the specs read. |
| [`Build/playwright/`](../../Build/playwright)                                                  | The Playwright configuration and a `package.json` of its own.          |
| [`Tests/Acceptance/manifest.ts`](../../Tests/Acceptance/manifest.ts)                           | Reads what the seeding script wrote.                                   |
| [`Tests/Acceptance/fixtures.ts`](../../Tests/Acceptance/fixtures.ts)                           | The reset between specs, the login, and the collected browser errors.  |
| [`Tests/Acceptance/Support/`](../../Tests/Acceptance/Support)                                  | The page object of the editing surface.                                |
| [`Tests/Acceptance/Frontend/`](../../Tests/Acceptance/Frontend)                                | The specs.                                                             |

The configuration sits in `Build/` next to `Build/phpunit/` and `Build/phpstan/`
because that is where every tool's configuration lives; the specs sit in `Tests/`
next to `Tests/Unit/` and `Tests/Functional/` because they are tests.

## How the instance is built

```
runTests.sh -s acceptance
  │
  ├─ 1. IMAGE_PHP        setupAcceptanceInstance.php
  │        → .Build/Web/typo3temp/var/tests/acceptance/          the instance
  │        → .Build/Web/typo3temp/var/tests/acceptance-db/       the SQLite file and its snapshot
  │        → .Build/Web/typo3temp/var/tests/acceptance.json      the manifest
  │
  ├─ 2. IMAGE_PHP        php-fpm      --network-alias phpfpm
  ├─ 3. IMAGE_APACHE     httpd        --network-alias web        docroot = the instance
  │     waitFor web 80
  │
  └─ 4. IMAGE_PLAYWRIGHT npm ci && playwright test               baseURL = http://web/
```

All four containers are attached to the `runTests.sh` network, which is what
makes `cleanUp()` remove them — it enumerates the network rather than a list of
names.

**The browser reaches the site as `http://web/`, and the site configuration
carries exactly that as its base.** TYPO3 resolves a site by the host it was
requested with, so the two strings have to agree; that is why no host port is
published and why the apache container's `APACHE_RUN_SERVERNAME` is `web` as
well.

### Why `Testbase` and not `vendor/bin/typo3 setup`

TYPO3 core builds its own Playwright instance as a second composer project and
installs it with `vendor/bin/typo3 setup`. That is not available here:
`SetupCommand` lives in `typo3/cms-install`, which this dependency set does not
contain, and adding it would grow the install of every other gate for both core
versions.

`typo3/testing-framework`'s `Testbase` builds this kind of instance by design —
its `setUpInstanceCoreLinks()` rewrites the entry point to the framework's own
`SystemEnvironmentBuilder`, in a comment that says it does so "because acceptance
tests will make use of them". The result is a **non composer mode** instance with
its own `index.php`, its own `typo3conf/system/settings.php` and its own
database, which needs no second composer project and writes nothing into the
repository root — the latter is not a preference: `typo3/cms-composer-installers`
v5 removed `app-dir` and always writes `config/system/settings.php` into the
composer root.

One thing has to be patched afterwards. The generated `index.php` requires
`.Build/vendor/autoload.php`, whose `files` autoload sets `TYPO3_PATH_ROOT` to
the composer web directory unless it is already set — and
`SystemEnvironmentBuilder` prefers that over the directory the entry script is
in. A functional test never notices, because `FunctionalTestCase::setUp()` calls
`putenv()` first; a web request has nobody to do that. The seeding script
therefore injects the two `putenv()` calls at the top of the entry point, and
fails loudly if the generated file no longer has the expected shape.

### The fixtures are the functional ones

`ProfileSite.csv`, `ProfilePlugins.csv`, `ProfileEditPlugin.csv` and
`ProfileAjaxRecords.csv` are imported through `DataSet::import()`, which is the
public, static entry point behind `importCSVDataSet()`. Reusing them rather than
writing a second set means a record added for a functional test is in the browser
run as well, and that the two suites cannot describe different profiles.

The site is configured through **site sets**, not through a `sys_template`
record: it needs no record at all, so the whole TypoScript configuration of the
instance is two files next to each other. Both flavours are covered by the
functional suite, so choosing one here costs no coverage.

### Logging in without a login form

EXT:felogin is not a dependency of this extension, and adding one so that a test
harness can fill in a form would be the wrong trade. The seeding script creates a
real frontend user session per fixture user with core's own `UserSessionManager`
— the same three calls the testing framework's `FrontendUserHandler` middleware
makes for `InternalRequestContext::withFrontendUserId()` — and writes the
`fe_typo_user` cookie value into the manifest. A spec adds that cookie to its
browser context; everything after it is the production path, including core's
`FrontendUserAuthenticator`.

The instance sets `FE/lockIP = 0` for it. The session is created in a CLI process
that has no remote address and used from a different container, so an IP lock
would refuse it.

## The reset between specs

Every spec starts from a byte copy of a snapshot the seeding script took, and
this is a deliberate divergence from core, which does not reset at all and has
each spec restore what it changed. That is not good enough for this suite: the
assertion that matters here is *the value is still there after a reload*, and a
leftover from an earlier spec is indistinguishable from a write that worked.

Two things make the copy trustworthy:

1. The `-wal` and `-shm` sidecars are removed **with** the file. The instance
   runs with `journal_mode = WAL`, so the main file is not necessarily the whole
   database, and a copy that leaves a stale sidecar behind restores nothing.
2. The restored database is then compared with the snapshot **row by row**, and
   the reset fails when they differ.

The second is not a row count on purpose. Removing a child is a *soft* delete, so
a count over the whole table is identical before and after it — a reset that
silently did nothing would pass a count and only surface much later, as a spec
failing for a reason that looks like a browser problem.

The comparison is proven to work rather than assumed: with the copy removed, the
suite fails from the third spec on with
`The database reset did not restore the seeded state`, which is the first spec
that reads state an earlier one changed.

The reset is why the suite runs with `workers: 1` and `fullyParallel: false`.
One database, one worker.

## Retries are off, and stay off

`retries: 0`, and `trace: 'retain-on-failure'` rather than `on-first-retry`.
Core retries twice; a browser test that only passes on the second attempt is a
defect that has been hidden, and a failure has to be debuggable from the first
and only run.

Artifacts land under `.Build/Web/typo3temp/var/tests/`, which is the path
`-s cleanTests` removes and the CI job uploads on failure:

| Path                            | Contents                                            |
|---------------------------------|-----------------------------------------------------|
| `playwright-results/`           | Traces, screenshots and error contexts of failures. |
| `playwright-reports/`           | The HTML report.                                    |
| `acceptance/typo3temp/var/log/` | The TYPO3 log of the instance.                      |

```bash
npx playwright show-trace .Build/Web/typo3temp/var/tests/playwright-results/<test>/trace.zip
```

## Two `package.json` files, on purpose

[`Build/package.json`](../../Build/package.json) is the **asset build**: it
compiles `Build/Sources/TypeScript/` into `Resources/Public/JavaScript/`, and
those artifacts are committed and shipped.
[`Build/playwright/package.json`](../../Build/playwright/package.json) is a test
dependency and nothing else. Sharing one manifest would mean a Playwright bump
forces a rebuild and a diff of shipped JavaScript, and it would pull the browser
drivers into every `npm ci` of the six node suites.

The Playwright image tag and the `@playwright/test` version are pinned to the
same number. The image ships the browsers, the package ships the runner that
drives them, and a runner newer than its browsers fails in a way that reads like
a broken test. `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` is what keeps `npm ci` from
downloading a second set.

`NODE_PATH` is set to `Build/playwright/node_modules` for the run. Node resolves
`node_modules` by walking up from the importing file, and the specs deliberately
do not live next to the manifest that installs the runner; the alternative is a
`node_modules` in the repository root, i.e. a third `package.json`.

## Selecting elements in a shadow root

Playwright's CSS engine pierces open shadow roots, so the selectors read like
ordinary ones. What they are anchored on is deliberate:

- **Fields by `data-focus`**, which is `<targetKey>|<field>` — `profile|firstname`,
  `address:7|line1` — the same string the component uses to move the focus. It is
  structural, so a retranslated label does not touch a spec.
- **Buttons by their accessible name**, which is the label the server translated
  into `data-labels`. Addressing them by position would pass for a surface that
  renders *Remove* where *Hide* belongs.

Focus is the one thing that cannot be read naively: `document.activeElement`
answers with the *host* of a shadow root, so a plain read reports the custom
element for every control inside it. `ProfileEditPage.focusedField()` walks into
the shadow roots and back up to the field element.

## What the specs cover

| Spec                                                                                               | Proves                                                                                                                                                              |
|----------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [`InlineEdit.spec.ts`](../../Tests/Acceptance/Frontend/InlineEdit.spec.ts)                         | A saved field is served after a reload; cancel reverts to the last **server known** value; a `422` shows at the field and keeps the draft; focus, Enter and Escape. |
| [`ChildCollections.spec.ts`](../../Tests/Acceptance/Frontend/ChildCollections.spec.ts)             | The stored order including the owner's hidden record; adding stores what was typed; reorder and removal survive a reload; unhiding is persisted.                    |
| [`ProgressiveEnhancement.spec.ts`](../../Tests/Acceptance/Frontend/ProgressiveEnhancement.spec.ts) | The profile is readable without JavaScript; `lit` resolves from the import map; the upgraded element does not slot its light DOM.                                   |

Every spec that asserts a write asserts it twice: the **reloaded page** has to
serve the new value, which is the only proof that the server persisted it rather
than the component having redrawn itself, and the **raw row** has to carry it,
which is the only proof of what was stored.

The add spec is where that pair earns its keep. The add form is the one record
that is typed into without ever being *opened* — its controls are always
rendered, so nothing calls `beginFieldEdit()` and its session holds drafts and
nothing else. A component that discards those drafts submits the values a new
record starts from while the controls still show what was typed: the surface
looks right, and only the row and the reload disagree. `editState.test.ts` pins
the state machine half of that; this spec is what proves the two agree in a
browser.

The `lit` assertion is the one nothing else in the repository can make. The PHP
suite asserts that an import map was emitted; only a browser can assert that a
bare specifier resolves out of it. Without it, a `lit` major version bump in
TYPO3 core reaches this extension as a broken page rather than as a red gate.

## Known gaps

- **SQLite only.** The reset is a file copy, which is what makes a per spec reset
  affordable at all. Nothing a spec asserts is something a second database
  platform could disagree about; the queries are covered by
  `-s functional -d mariadb|mysql|postgres`.
- **The page cache is off** in the acceptance instance. A cached rendering would
  let "the server serves the new value" pass over a write that never happened.
  That the edit plugin is `USER_INT` is asserted by `ProfileEditPluginTest`
  instead.
- **The remaining refusal conditions of the component** — a malformed
  `data-profile`, an `ajaxPageType` of `0`, a missing request token — are not
  covered. Each needs a differently misconfigured instance, i.e. a second seeded
  instance per condition.

## See also

- [Testing](Index.md)
- [Quality gates](../development/quality-gates.md)
- [The edit plugin](../frontend-edit/edit-plugin.md) — the degradation table and
  the list of behaviours that were named as acceptance-only.
- [Frontend assets](../frontend-edit/frontend-assets.md) — the import map this
  suite proves resolves.
