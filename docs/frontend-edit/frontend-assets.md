# Frontend assets

The edit UI is a lit web component, loaded in the frontend as an ES module
through a TYPO3 import map, with its styles split between the component's shadow
root and one page-level stylesheet. Everything about that sentence was contested
at least once, so this page records what was verified and what was decided.

> [!NOTE]
> **The toolchain exists.** `Build/package.json`, `Build/esbuild.mjs`,
> `Build/tsconfig.json`, `Build/eslint.config.mjs`, `Build/Sources/`, the five
> `runTests.sh` suites, `Configuration/JavaScriptModules.php` and the compiled
> artifacts below `Resources/Public/` are in the repository, and the CI workflow
> has a job for them.
>
> What does **not** exist yet is the edit UI itself: the entry point is
> scaffolding that sets one class on `<html>`, no Fluid template calls
> `f:asset.module` yet, and no test asserts that the specifier resolves. Those
> are named again where this page describes them.

## Import maps work in the frontend, on both core versions

They are not a backend feature, and they never were.
`PageRenderer::renderMainJavaScriptLibraries()` emits the import map regardless
of the application type. The *only* thing gated on the backend is the nonce:

```php
// .Build/vendor/typo3/cms-core/Classes/Page/PageRenderer.php:1289-1293 (14.3.6)
$useNonce = $this->getApplicationType($request) === 'BE';
$out .= $this->javaScriptRenderer->renderImportMap(
    $sitePath,
    $useNonce ? $this->nonce : null,
);
```

TYPO3 v13.4 carries the same three statements; it differs only in that
`getApplicationType()` takes no request there. Beyond that, `ImportMap`,
`JavaScriptRenderer` and `ImportMapFactory` contain **no application type check
at all** on either version — grepping the three classes for `ApplicationType`
returns nothing. The map is emitted whenever at least one package was loaded
(`ImportMap.php:136-138`), and a package is loaded by requesting one of its
modules (`JavaScriptRenderer.php:59-69`).

The changelog says it outright, in the v12.0 entry that introduced the
mechanism:

> JavaScript ES6 modules may now be used instead of AMD modules, both in backend
> and frontend context.
>
> — Feature #96510, *Infrastructure for JavaScript modules and importmaps*

The single backend/frontend difference is `includeAllImports()`, documented as
"Do only use in authenticated mode as this discloses as installed extensions"
(`ImportMap.php:63-65`). It has exactly two callers, both in `EXT:backend`
(`BackendController.php:143`, `ModuleTemplate.php:165`). **The frontend
therefore gets a selective map**: what we declared, plus the maps of our
declared `dependencies`, and nothing else. A specifier that is neither declared
nor depended on is not silently missing — `JavaScriptRenderer::render()` throws
`1728220800` naming the module and pointing at
`Configuration/JavaScriptModules.php` (`JavaScriptRenderer.php:117-124`).

### Consequence: asset loading needs no `Core13`/`Core14` split

The configuration file format, the `dependencies` key, the recursive
`'prefix/' => 'EXT:…/'` mapping, `PageRenderer::loadJavaScriptModule()`,
`AssetCollector::addJavaScriptModule()` and `<f:asset.module>` behave
identically on 13.4 and 14.3. The differences that do exist are confined to
`@internal` code (`ImportMap` is marked `@internal`, `ImportMap.php:41-43`) and
are invisible to an extension:

| Aspect                | v13.4                                                          | v14.3                                                                                      |
|-----------------------|----------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| URI generation        | `PathUtility::getPublicResourceWebPath()`, prefix concatenated | `PathUtility::getSystemResourceUri()` with `UriGenerationOptions` (Feature #107537, v14.0) |
| Cache-bust suffix     | skipped for paths containing a scoped-package `@`              | always applied                                                                             |
| `VIRTUAL:`/`~labels/` | absent                                                         | present (Feature #108941, v14.2)                                                           |

[Rule 1 of the agent instructions](../architecture/core-version-aware-code.md)
is therefore **not** triggered by asset loading, and a patch that introduces a
`Core13`/`Core14` split for it should be challenged in review: there is no
behavioural difference to split on, and the split would have to be maintained
forever for a difference that does not exist. The one v14-only convenience —
`~labels/` — is deliberately unused for exactly that reason.

Worth stating once, because it is user visible: the import-map polyfill
mentioned in Feature #96510 is gone from both 13.4 and 14.3, so the browser
floor is native import-map support — Chrome 89+, Firefox 108+, Safari 16.4+.
That floor is a core decision we inherit, not one this extension takes. Feature
#96510 also documents that ES modules are the only mechanism from v13 on;
RequireJS was removed by Breaking #101266 (v13.0).

## `lit` is mapped, never bundled

`lit` is declared in **`EXT:core`**'s own import map, not in `EXT:backend`:

```php
// .Build/vendor/typo3/cms-core/Configuration/JavaScriptModules.php:32-37
'lit'          => 'EXT:core/Resources/Public/JavaScript/Contrib/lit/index.js',
'lit/'         => 'EXT:core/Resources/Public/JavaScript/Contrib/lit/',
'lit-element'  => 'EXT:core/Resources/Public/JavaScript/Contrib/lit-element/index.js',
'lit-element/' => 'EXT:core/Resources/Public/JavaScript/Contrib/lit-element/',
'lit-html'     => 'EXT:core/Resources/Public/JavaScript/Contrib/lit-html/lit-html.js',
'lit-html/'    => 'EXT:core/Resources/Public/JavaScript/Contrib/lit-html/',
```

The same six lines are at the same offsets on 13.4, and the shipped version is
`lit ^3.2.0` on both. Because the declaration sits in `EXT:core`, a single
`'dependencies' => ['core']` in our `Configuration/JavaScriptModules.php` pulls
those specifiers into the **frontend** map. Nothing needs to be vendored.

Two independent reasons make this the only correct option, not merely the
cheapest one:

1. **A path reference is impossible anyway.** Core's shipped entry file uses
   bare specifiers itself:

   ```js
   // .Build/vendor/typo3/cms-core/Resources/Public/JavaScript/Contrib/lit/index.js
   import"@lit/reactive-element";import"lit-html";export*from"lit-element/lit-element.js";…
   ```

   Loading it by URL yields a module whose own imports cannot resolve. The
   import map is the only route, which is why `dependencies` exists.

2. **Two lit runtimes on one page is a correctness problem.** Not a payload
   argument — the ~18 KB is irrelevant. Two copies mean two `ReactiveElement`
   registries, `instanceof` checks that fail across them, and a duplicate
   `customElements.define()` that throws on the second registration. lit is a
   *shared* dependency in this ecosystem: an editor viewing the frontend, or any
   second extension shipping a lit component, is enough to hit it.

The build therefore marks `lit`, `lit-html`, `lit-element`, `@lit/*` and
`@typo3/*` **external** (`Build/esbuild.mjs:37-47`), and the sources import the
bare specifier (`lit`, `lit/decorators.js`) and never a path. `lit` is a
`devDependency` of the build — it is present for `tsc` and the editor and is
never emitted.

`Configuration/JavaScriptModules.php` is therefore three lines of substance:

```php
return [
    'dependencies' => ['core'],
    'imports' => [
        '@sbuerk/modern-extbase-frontend-edit/' => 'EXT:modern_extbase_frontend_edit/Resources/Public/JavaScript/',
    ],
];
```

`dependencies` names **`core`, not `backend`** — that single word is what makes
`lit` resolvable from a frontend page without bundling it, because the six `lit`
specifiers are declared in `EXT:core`'s own module map. Naming `backend` instead
would pull in the backend's module map, which a frontend page has no business
loading, and would still not be the place `lit` comes from. No `tags` are
declared: tags exist so the backend can eagerly load whole groups of modules,
while a frontend page loads exactly the one module its template asks for.

Stated honestly, the accepted downsides: our components run against *core's* lit
patch version, so a future lit major in core changes the API under us; and using
`<f:asset.module>` in the frontend puts core's Contrib specifiers into the
anonymous import map. The second is not a security issue — those are core's own
file names and the core version is discoverable anyway. The first is currently
**unmitigated**: the functional test asserting that the specifier resolves is
named here as the intended safety net and does not exist yet, so a lit major in
core would surface as a broken page rather than as a red gate. It lands with the
component that first imports `lit`.

## The toolchain, and why it is smaller than core's

Core builds JavaScript with `grunt → tsc → rollup` plus a second `esbuild` pass
for vendor libraries. That chain is not overhead; it buys core something
specific that an extension does not need: **unbundled 1:1 emit**. Every one of
core's roughly 800 TypeScript modules becomes exactly one `.js` file,
individually addressable by specifier and shared across sysexts, with every
import left intact. Grunt sequences the tasks across thirty extensions, and
rollup does the per-file rewriting and the tagged-template minification.

We ship a handful of modules behind one or two entry points. The pipeline that
follows from that is:

| Core's choice                                         | Here           | Reason                                                                                                                                                 |
|-------------------------------------------------------|----------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| `grunt` as task runner                                | drop           | Two tasks, not fifteen. One `esbuild.mjs` called from an npm script has no plugin-maintenance surface.                                                 |
| `tsc` for **emit**                                    | drop           | esbuild transpiles TypeScript directly and honours `experimentalDecorators`/`useDefineForClassFields` from `tsconfig.json`.                            |
| `tsc` for **type checking**                           | **keep**       | esbuild does not type check. Without `tsc --noEmit` the build succeeds on code that does not compile. The non-obvious keep.                            |
| `rollup` + `litnano` + `rollup-plugin-esbuild`        | drop           | Exists for 1:1 emit over a huge shared graph and for minifying `css`/`html` templates. Neither applies at this size.                                   |
| `esbuild`                                             | keep, promoted | Becomes the whole build: TypeScript to ESM, per-entry bundling of our own modules, CSS minification.                                                   |
| `sass` + `postcss` + `autoprefixer` + `cssnano`       | drop           | Core needs sass because Bootstrap is sass. Native nesting and custom properties cover a component stylesheet; esbuild minifies it.                     |
| `eslint` 9 + `typescript-eslint` + `lit`/`wc` plugins | **keep**       | They catch mistakes no compiler sees: `lit/no-legacy-imports`, `lit/no-native-attributes`, `wc/require-listener-teardown`, `wc/no-constructor-params`. |
| `stylelint` 14 + a 160-line rc file                   | drop           | Core pins the stylelint-14 stylistic ruleset, most of which was removed in stylelint 15+. Adopting it means inheriting a dead end.                     |

`typescript` is thus a dependency of this build **solely** as `tsc --noEmit`,
and that is why type checking is its own gate rather than a step inside the
build — a build that is green and a type check that is red must be
distinguishable.

Two `tsconfig.json` settings are load bearing and are taken from core verbatim:
`experimentalDecorators: true` together with `useDefineForClassFields: false`.
That pair is the legacy decorator mode lit 3 requires; getting it wrong produces
`@property` decorators that silently do nothing. One setting deliberately
diverges from core: `strict: true` where core has `strict: false`. Core carries
years of legacy, a new extension has no reason to start relaxed.

The browser target of the build is not a taste decision either. `esbuild.mjs:55`
sets `['chrome89', 'firefox108', 'safari16.4']`, which is the floor of the
import-map mechanism itself. Targeting anything older would emit transpiled
output for browsers that cannot resolve the module in the first place — and it
is the reason the stylesheet may use native nesting: esbuild lowers nesting to
that same floor, and Safari 16.4 has import maps but not native nesting.

Type aware eslint rules are deliberately **not** enabled. `tsc --noEmit` is the
type gate; what the lit and web-component plugins add are the mistakes a
compiler does not see — legacy `lit` imports, reflected native attributes,
listeners without teardown, constructor parameters on a custom element.

Deliberately deferred, and named as gaps rather than hidden: real-browser
JavaScript unit tests (`@web/test-runner` needs a ~700 MB Chrome image and a
hand-written import map), and stylelint 16 — to be added if and when the CSS
grows beyond a single file.

## Layout

```
Build/                                       (already export-ignored)
  package.json  package-lock.json
  tsconfig.json  eslint.config.mjs  esbuild.mjs
  Sources/
    TypeScript/frontend-edit.ts              entry point
    TypeScript/documentState.ts              internal, bundled into the entry
    Css/frontend-edit.css
Resources/Public/
  JavaScript/frontend-edit.js                committed build artifact
  Css/frontend-edit.css                      committed build artifact
Configuration/JavaScriptModules.php
```

`frontend-edit.ts` is the entry point that stays; its body is scaffolding that
the edit UI replaces. `documentState.ts` is the one thing the entry does today:
it sets `frontend-edit-loaded` on `<html>`, and every rule in the stylesheet is
scoped to that class. A stylesheet loaded through `f:asset.css` applies whether
or not the module behind `f:asset.module` ran, so without that gate a page whose
module failed to resolve would show edit affordances that nothing responds to.

Three conventions hold this together:

- **One entry point per import-map specifier.** Only entry points appear in
  `entryPoints`; modules they import are bundled into them and get no specifier
  of their own. This is the opposite of core's 1:1 mapping, and it is the right
  trade at this size — the addressable surface stays exactly as large as the
  set of things a template may load.
- **Shadow-DOM styles live in the TypeScript**, as ``static styles = css`…` ``.
  They cannot come from a `<link>`, and this is what core's own lit components
  do. The build has nothing to do for them.
- **Only page-level, light-DOM CSS is emitted as a file**, from
  `Build/Sources/Css/*.css`. There is no third mechanism, and no inline style
  block.

Source maps are not committed — inline maps in the dev build only, matching
core's `*.js.map` ignore.

## How Fluid loads it

> [!NOTE]
> **No template does this yet.** The import map entry and the artifacts exist;
> the two tags below land with the edit plugin that needs them. Until then the
> module is addressable and nothing addresses it, which is why the `f:asset.css`
> and `f:asset.module` calls are shown here as the intended wiring rather than
> quoted from a template.

```html
<f:asset.css
    identifier="modernExtbaseFrontendEdit"
    href="EXT:modern_extbase_frontend_edit/Resources/Public/Css/frontend-edit.css"
/>
<f:asset.module identifier="@sbuerk/modern-extbase-frontend-edit/frontend-edit.js"/>
```

`f:asset.module` exists on both versions with the same single `identifier`
argument and does nothing but call `AssetCollector::addJavaScriptModule()`; the
collector hands its modules to the `PageRenderer` at render time, which is what
puts the import map into the page.

**Both survive the frontend page cache.** `RequestHandler` serialises the
`PageRenderer` state — which carries the import-map state — and the
`AssetCollector` state into the page cache entry:

```php
// .Build/vendor/typo3/cms-frontend/Classes/Http/RequestHandler.php:199-200
'pageRendererState' => serialize($this->pageRenderer->getState()),
'assetCollectorState' => serialize(GeneralUtility::makeInstance(AssetCollector::class)->getState()),
```

and `PrepareTypoScriptFrontendRendering` restores both on a cache hit. A cached
content element therefore still yields the import map and the script tag; no
`USER_INT` is needed for the assets. (The edit plugin markup is `USER_INT` for
an unrelated reason — the request token must not be cached.)

Two things are deliberately not used:

- **`useNonce`** on `f:asset.css`/`f:asset.script` — deprecated in favour of
  `csp` by Deprecation #100887 (v14.2). Passing neither argument is
  version neutral and is what we do.
- **The `HeaderAssets`/`FooterAssets` Fluid sections** — deprecated by
  Deprecation #107057 (v14.0), which names `f:asset.script`/`f:asset.css` as the
  replacement.

Data reaches the component as **attributes on the custom element in the Fluid
template**, not as `JavaScriptModuleInstruction` items. That keeps the markup
cacheable and the component testable in isolation.

One operational caveat belongs in an integrator's head: the computed import map
is cached in `cache.assets`, and for a `'prefix/' => 'EXT:…/'` mapping the file
list is enumerated once and cached. Adding a new `.js` file in production needs
a cache flush; in `Development` context the bust value is
`$GLOBALS['EXEC_TIME']` and it recomputes per request.

## Artifacts are committed, and that makes a gate mandatory

`Resources/Public/JavaScript/*.js` and `Resources/Public/Css/*.css` are tracked
files. This is not a preference:

- **Core does the same** — its `Contrib/` JavaScript and `backend.css` are
  tracked, and only the intermediates (`Build/JavaScript`, `Build/node_modules`,
  `*.js.map`) are ignored.
- **Composer distribution requires it.** `composer require` runs no node build,
  and the installed `.Build/vendor/typo3/cms-core/` contains
  `Resources/Public/JavaScript/**` and no `Build/` directory at all.
- **TER requires it.** A TER upload is a ZIP of the working tree; there is no
  build hook.
- **This repository is already wired for it.** `.gitattributes:5` carries
  `/Build export-ignore`, so the sources, `package.json` and the build config
  stay out of `composer archive` and the dist tarball without any change.

The consequence has to be stated plainly: **a committed artifact that no longer
matches its source is a silent defect.** It passes every review, ships to every
installation, and is only discovered when someone wonders why a fix had no
effect. Nothing else in the repository can notice it: `cgl`, PHPStan and the
PHPUnit suites never look at `Resources/Public/`, and the artifact keeps
serving the previous behaviour perfectly happily. `checkJsBuildClean` —
rebuild from scratch, then assert the working tree is clean — is therefore
**mandatory, not optional**. Without it, committing artifacts is a liability
rather than a distribution mechanism. Core solves it the same way with
`checkGruntClean`.

Three details of how it is implemented follow from that, and each is a decision:

- **The artifacts are deleted before the rebuild, not overwritten.** A source
  file that stopped producing an output is then caught too — `git status`
  reports the deletion. Overwriting would leave the stale file in place and the
  tree clean.
- **The working tree is inspected with `git status --porcelain
  --untracked-files=all -- Resources/Public`,** so a *new* untracked artifact
  counts as drift as well. A green run leaves the tree exactly as it found it; a
  red one leaves the rebuilt files in place, which is what the printed diff is
  showing.
- **`safe.directory` is passed as `GIT_CONFIG_*` environment,** not written to a
  config file. In CI the container runs as root against a checkout owned by the
  runner user, and git refuses to operate in a repository owned by someone else.

## Correction: `.gitignore` was never what kept php-cs-fixer out

An earlier revision of this page stated that adding `/Build/node_modules` to
`.gitignore` is what stops `cgl` from walking the npm dependencies, because
`Build/php-cs-fixer/config.php` combines `ignoreVCSIgnored(true)` with an
`in()` on `Build/`. **That is wrong, and the way it is wrong is worth keeping:
the protection everyone assumed was in place had never once been active.**

`ignoreVCSIgnored(true)` was a complete no-op in this repository. Symfony's
`VcsIgnoredFilterIterator` walks up from the `in()` path looking for a `.git`
directory and keeps **the string it walked to** as its base:

```php
// .Build/vendor/symfony/finder/Iterator/VcsIgnoredFilterIterator.php:36-48
public function __construct(\Iterator $iterator, string $baseDir)
{
    $this->baseDir = $this->normalizePath($baseDir);

    foreach ([$this->baseDir, ...$this->parentDirectoriesUpwards($this->baseDir)] as $directory) {
        if (@is_dir("{$directory}/.git")) {
            $this->baseDir = $directory;
            break;
        }
    }
    // …
}
```

`normalizePath()` only converts backslashes on Windows
(`VcsIgnoredFilterIterator.php:165-172`) and `parentDirectoriesUpwards()` is
plain repeated `dirname()` (`:102-120`) — neither resolves `..`. The finder was
handed `__DIR__ . '/../../Build'`, so the first parent that has a `.git` beside
it is the literal string `…/Build/php-cs-fixer/../..`, and that is what `baseDir`
became.

The files being filtered, however, arrive as **real** paths — `accept()` calls
`getRealPath()` (`:50-57`). Selecting the directories whose `.gitignore` should
apply is a string prefix test against that base:

```php
// .Build/vendor/symfony/finder/Iterator/VcsIgnoredFilterIterator.php:122-128
private function parentDirectoriesUpTo(string $from, string $upTo): array
{
    return array_filter(
        $this->parentDirectoriesUpwards($from),
        static fn (string $directory): bool => str_starts_with($directory, $upTo)
    );
}
```

No real path starts with `…/Build/php-cs-fixer/../..`. The array came back
empty, the loop over candidate directories (`:71-94`) never ran, **no
`.gitignore` was ever read**, and `isIgnored()` returned `false` for every file.
The `.gitignore` entry was doing nothing at all; php-cs-fixer walked
`Build/node_modules` and the only reason nobody noticed is that eslint's
dependency tree happens to contain exactly one PHP file,
`Build/node_modules/flatted/php/flatted.php`.

The fix is two changes in `Build/php-cs-fixer/config.php`, and both are needed:

1. **Resolve the paths.** `$root = dirname(__DIR__, 2)` and `$root . '/Build'`
   instead of `__DIR__ . '/../../Build'`. `baseDir` then becomes the repository
   root, the prefix test matches, and `ignoreVCSIgnored()` does what its name
   says.
2. **Exclude the directory by name as well.** `->exclude(['node_modules/', …])`
   does not depend on git at all, so it also holds in an exported tree with no
   `.git` — a checkout is not something a code-style run should require.

The lesson generalizes past this one finder: **an option whose failure mode is
"silently filters nothing" needs to be observed working, not configured.** The
observation here is one line of output — the same `Finder` over the same
directory returns eight PHP files with the relative `in()` path and seven with
the resolved one.

The two neighbouring gates needed the exclusion spelled out for their own
reasons, unrelated to git:

| Gate       | What changed                                            | Why                                                                                                                                  |
|------------|---------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| `checkBom` | `! -path "./Build/node_modules/*"` added to the `find`  | Running `file` over tens of thousands of npm files takes minutes, and npm packages do ship BOM'd files this repository does not own. |
| `lintPhp`  | *(no exclusion in the `find` yet — see the note below)* | `php -l` over the same tree is slow for the same reason, and reports on code that is not ours.                                       |

> [!NOTE]
> `lintPhp` still descends into `Build/node_modules`. The suite carries a
> comment saying it does not, but its `find` excludes only `.Build/`, `.agent/`
> and `.cache/`. It costs a few seconds today because exactly one PHP file is
> down there and it happens to be syntactically valid; it is a real
> inconsistency and belongs in the next change that touches `runTests.sh`.

`Build/Scripts/checkMarkdownTables.php` and
`Build/Scripts/duplicateExceptionCodeCheck.sh` needed **no** change — both are
already scoped to explicit paths.

## The `runTests.sh` suites

All of them run in **`ghcr.io/typo3/core-testing-nodejs24:1.1`**, the image TYPO3
core uses for its own JavaScript suites. It answers `v24.14.1`, `11.11.0` and
`git version 2.39.5` — node and npm inside the `engines` range of
`Build/package.json`, and the `git` that `checkJsBuildClean` needs, which is not
something a node image can be assumed to carry.

It is **pinned to `:1.1`, not `:latest`** — deliberately, and the way core pins
it. The PHP and documentation images this repository uses are `:latest`, so the
difference needs a reason: a node major changing under a *committed* build
artifact would produce a `checkJsBuildClean` failure in a pull request that
changed no JavaScript at all. That is precisely the surprise the gate exists to
catch, not to manufacture. Moving to a new node major is then a visible one-line
commit rather than a Tuesday.

Each suite gets `-e HOME=${ROOT_DIR}/.cache` so npm's cache lands in the
`.cache/` directory CI already caches — the same reason the composer and PHPStan
caches live there and
[not under `.Build/`](../development/quality-gates.md#the-composer-cache).

| Suite               | Runs                                                                          | Purpose                                                                                                                  |
|---------------------|-------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------|
| `buildJs`           | `npm ci && npm run build` in `Build/`                                         | Compiles TypeScript and CSS into `Resources/Public/`. Run after every source change, and commit the result.              |
| `checkJsBuildClean` | Delete the artifacts, `npm ci && npm run build`, assert `git status` is empty | The gate that makes committed artifacts trustworthy. CI critical.                                                        |
| `lintTypescript`    | `npm run lint:fix`, or `lint` when `-n` is given                              | eslint 9 with typescript-eslint and the lit/wc plugins. Mirrors `cgl`, which fixes by default and only checks with `-n`. |
| `typecheckJs`       | `npm run typecheck`                                                           | `tsc --noEmit`. A separate suite precisely because esbuild does not type check.                                          |
| `npm`               | `npm "$@"` with the working directory set to `Build/`                         | Escape hatch, mirroring the existing `composer` suite: `-s npm -- install --save-dev lit@latest`.                        |
| `cleanJs`           | `rm -rf Build/node_modules Build/.cache`                                      | Intermediates only. It never removes `Resources/Public/` — those are committed files.                                    |

`cleanJs` is also wired into `clean`, next to the existing
`cleanCacheFiles()`/`cleanTestFiles()`. That `checkJsBuildClean` deletes
`Resources/Public/JavaScript` and `Resources/Public/Css` while `cleanJs` refuses
to is not an inconsistency: the gate deletes them *in order to* rebuild them in
the same command, and a `clean` that leaves the working tree with deleted
tracked files would be a trap.

Two properties of these suites differ from every gate documented so far, and the
`-h` output says so:

- **They are core version independent.** They inspect `Build/Sources/` and
  `Resources/Public/`, never the installed core, so `-t` does not change what
  they do and running them in both halves of the `-t 13` / `-t 14` matrix would
  check the same files twice.
- **They need no `composerUpdate`,** because they never touch `.Build/`. That
  makes them the only suites that are safe to run while the *other* core
  version's dependency set is installed — the one exception to the rule in
  [Dual core setup](../development/dual-core-setup.md).

## The CI job

`.github/workflows/ci.yml` gets **one** job, `frontend-assets`, and it is listed
in the `needs` of `ci-status` like every other job — a skipped or cancelled job
fails the aggregate, so the gate cannot quietly disappear.

```yaml
frontend-assets:
  name: "frontend assets"
  runs-on: ubuntu-latest
  steps:
    # checkout, then cache ".cache/.npm" keyed on Build/package-lock.json
    - run: "Build/Scripts/runTests.sh -b docker -s lintTypescript -n"
    - run: "Build/Scripts/runTests.sh -b docker -s typecheckJs"
    - run: "Build/Scripts/runTests.sh -b docker -s checkJsBuildClean"
```

Three things about it are deliberate:

- **No matrix.** The suites never touch PHP or the installed core, so repeating
  them across PHP and core versions would check the same TypeScript four times.
  This is how core runs its JavaScript gates as well — a single integrity job
  rather than one per PHP version.
- **No `composerUpdate` step.** Nothing here reads `.Build/`, so the job skips
  the most expensive step in the workflow entirely. It is the only job that
  does.
- **`checkJsBuildClean` runs last.** Lint and type errors are cheaper to produce
  and easier to read than a diff of minified output; running the expensive,
  hardest-to-read gate first would bury them.

`-b docker` is passed for the same reason
[every other job passes it](../development/quality-gates.md#why-ci-passes--b-docker),
and has nothing to do with node.

## See also

- [Quality gates](../development/quality-gates.md)
- [Development environment](../development/environment.md)
- [Dual core setup](../development/dual-core-setup.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [DTOs and validation](dto-and-validation.md)
