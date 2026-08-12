# Frontend assets

The edit UI is a lit web component, loaded in the frontend as an ES module
through a TYPO3 import map, with its styles split between the component's shadow
root and one page-level stylesheet. Everything about that sentence was contested
at least once, so this page records what was verified and what was decided.

> [!NOTE]
> **The toolchain and the edit UI both exist.** `Build/package.json`,
> `Build/esbuild.mjs`, `Build/tsconfig.json`, `Build/eslint.config.mjs`,
> `Build/Sources/`, `Build/Tests/`, the `runTests.sh` suites listed below,
> `Configuration/JavaScriptModules.php` and the compiled artifacts below
> `Resources/Public/` are in the repository, the CI workflow has a job for them,
> and `Templates/ProfileEdit/Edit.html` loads the module and the stylesheet.
> What the component does with them is
> [The edit plugin](edit-plugin.md).
>
> The gap this page used to name as open — that nothing asserted **`lit`**
> actually resolving from the frontend import map — is closed. The acceptance
> suite imports it in the page's own realm and asserts what comes back, and it
> now asserts the per module entries of this extension as well.

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

Its sibling `includeTaggedImports()` (`ImportMap.php:71-83`) is the second way a
map could be widened, and it is worth recording that on 14.3 it is **dead
code**: nothing calls it but the one-line delegation in
`JavaScriptRenderer.php:84-87`, and not one of the three `JavaScriptModules.php`
files core ships — `EXT:core`, `EXT:backend`, `EXT:filelist` — declares a `tags`
key for it to match. `tags` is therefore not a backend mechanism this extension
declines to use; it is a mechanism nothing uses.

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

The build therefore needs no `external` list at all, and no longer has one. It
bundles nothing (`Build/esbuild.mjs:104`), so esbuild resolves no specifier and
every one of them — `lit`, `lit/decorators.js`, `lit/directives/repeat.js` —
survives into the emitted module exactly as written, for the browser to resolve
through the import map (`Build/esbuild.mjs:25-38`). An `external` list is what a
bundler has to be handed in order to be told which imports *not* to follow; a
build that follows no import needs none, and cannot drift out of step with the
set of packages core happens to declare. `lit` is a `devDependency` of the build
— it is present for `tsc` and the editor and is never emitted.

`Configuration/JavaScriptModules.php` is therefore three lines of substance:

```php
return [
    'dependencies' => ['core'],
    'imports' => [
        '@sbuerk/modern-extbase-frontend-edit/frontend/' => 'EXT:modern_extbase_frontend_edit/Resources/Public/JavaScript/frontend/',
    ],
];
```

The mapped prefix is **`frontend/`, not the whole `JavaScript/` directory**, and
that is a convention rather than a mechanism. TYPO3 has nothing that scopes a
map entry to one application type — `ImportMap` builds both maps from the same
declarations, and the only primitives that narrow anything are the `tags` above
and the backend's `includeAllImports()`. Mapping the two trees separately is
where the separation is written down: when this extension grows backend
JavaScript it gets a mapping of its own next to this one, and until then the
declaration cannot silently publish a backend module to a frontend page.
`Build/Sources/TypeScript/backend/` exists for that reason and holds nothing but
a `.gitkeep`.

`dependencies` names **`core`, not `backend`** — that single word is what makes
`lit` resolvable from a frontend page without bundling it, because the six `lit`
specifiers are declared in `EXT:core`'s own module map. Naming `backend` instead
would pull in the backend's module map, which a frontend page has no business
loading, and would still not be the place `lit` comes from. No `tags` are
declared, and per the finding above that costs nothing: a `tags` key is only
read by `includeTaggedImports()`, which nothing calls. A frontend page loads
exactly the one module its template asks for regardless.

Stated honestly, the accepted downsides: our components run against *core's* lit
patch version, so a future lit major in core changes the API under us; and using
`<f:asset.module>` in the frontend puts core's Contrib specifiers into the
anonymous import map. The second is not a security issue — those are core's own
file names and the core version is discoverable anyway.

The first is **closed**, and by the second of the two routes. It stayed open
while `ProfileEditPluginTest::theAssetsOfTheEditingSurfaceAreEmitted()` was the
only assertion: it asserts that an import map is emitted and that it carries
`@sbuerk/modern-extbase-frontend-edit/frontend/frontend-edit.js`, which is what a
`USER_INT` plugin has to get out of the non-cached pass — and it asserts nothing
about `lit`, because our own specifier resolving says nothing about the specifier
our module imports.

`ProgressiveEnhancement.spec.ts` of the
[acceptance suite](../testing/acceptance-tests.md) closes it in the only place it
can be closed: it reads the `lit` entry out of the emitted map, then evaluates
`await import('lit')` in the page and asserts that `LitElement`, `html` and `css`
are functions, and that both custom elements are registered afterwards. A lit
major version bump in core now reaches this extension as a red gate rather than
as a broken page.

The same spec is where the unbundled emit is held in place. It enumerates the
map entries under our prefix and asserts that there is more than one, that
`…/frontend/model/editState.js` is among them, and that each carries a `?bust=`
value. Asserting the prefix alone would not do it: a regression to a single
bundled file leaves the prefix entry looking exactly as it does now, and only
the per-module entries — the thing the cache busting depends on — disappear.

## The toolchain, and why it is smaller than core's

Core builds JavaScript with `grunt → tsc → rollup` plus a second `esbuild` pass
for vendor libraries. That chain is not overhead; it buys core **unbundled 1:1
emit**. Every one of core's roughly 800 TypeScript modules becomes exactly one
`.js` file, individually addressable by specifier and shared across sysexts,
with every import left intact. Grunt sequences the tasks across thirty
extensions, and rollup does the per-file rewriting and the tagged-template
minification.

This build emits 1:1 as well, which is the one property of it that is not
smaller than core's; why bundling was given up is
[below](#the-sources-import-each-other-by-bare-specifier). What does not follow
from it is the chain. esbuild produces the same shape by itself from
`bundle: false` and an `outbase`, in ten lines (`Build/esbuild.mjs:102-111`),
because neither job rollup carries for core exists here: there is no per-file
rewriting to do, since the sources already spell their imports the way the
emitted module has to — with the `.js` extension, and as the bare specifier the
import map resolves — and there is no tagged-template minification to do,
because nothing here is minified at all. The pipeline that follows is:

| Core's choice                                         | Here           | Reason                                                                                                                                                 |
|-------------------------------------------------------|----------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| `grunt` as task runner                                | drop           | Two tasks, not fifteen. One `esbuild.mjs` called from an npm script has no plugin-maintenance surface.                                                 |
| `tsc` for **emit**                                    | drop           | esbuild transpiles TypeScript directly and honours `experimentalDecorators`/`useDefineForClassFields` from `tsconfig.json`.                            |
| `tsc` for **type checking**                           | **keep**       | esbuild does not type check. Without `tsc --noEmit` the build succeeds on code that does not compile. The non-obvious keep.                            |
| `rollup` + `litnano` + `rollup-plugin-esbuild`        | drop           | esbuild emits 1:1 by itself, and minifying `css`/`html` templates has no counterpart in a build that minifies nothing.                                 |
| `esbuild`                                             | keep, promoted | Becomes the whole build: TypeScript to ESM, one emitted module per source module, and the one stylesheet.                                              |
| `sass` + `postcss` + `autoprefixer` + `cssnano`       | drop           | Core needs sass because Bootstrap is sass. Native nesting and custom properties cover a component stylesheet; esbuild lowers the nesting.              |
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

A third setting is there to pay for something the browser gets for free. Because
the sources import each other by the bare specifier the import map resolves —
[the reason is below](#the-sources-import-each-other-by-bare-specifier) — every
tool that reads them outside a browser has to be told where that specifier
lives. `tsconfig.json:46-50` does it with a `paths` entry onto
`./Sources/TypeScript/frontend/*`, deliberately without a `baseUrl`: with
`moduleResolution: bundler` the mapping targets already resolve relative to the
configuration file, and a `baseUrl` would additionally make every directory
below it importable as a bare specifier of its own. `node --test` needs the same
mapping and cannot read `paths`, so the resolve hook in
`Build/Tests/TypeScript/sourceResolve.mjs:36-40` rewrites the prefix onto the
source tree — next to the `.js`-to-`.ts` rewrite it already performed. Node's
own import-map support is behind `--experimental-*` flags and was not taken.
Two small mappings, in two files, is the price of the specifier.

The browser target of the build is not a taste decision either. `esbuild.mjs:46`
sets `['chrome89', 'firefox108', 'safari16.4']`, which is the floor of the
import-map mechanism itself. Targeting anything older would emit transpiled
output for browsers that cannot resolve the module in the first place — and it
is the reason the stylesheet may use native nesting: esbuild lowers nesting to
that same floor, and Safari 16.4 has import maps but not native nesting.

Type aware eslint rules are deliberately **not** enabled. `tsc --noEmit` is the
type gate; what the lit and web-component plugins add are the mistakes a
compiler does not see — legacy `lit` imports, reflected native attributes,
listeners without teardown, constructor parameters on a custom element.

### Both gates cover every TypeScript tree, including the acceptance suite

There are four of them, and they do not all live under `Build/`:

| Tree                        | What it is                   | eslint gets               | `tsc` project             |
|-----------------------------|------------------------------|---------------------------|---------------------------|
| `Build/Sources/TypeScript/` | the shipped modules          | browser globals, lit + wc | `Build/tsconfig.json`     |
| `Build/Tests/TypeScript/`   | the `unitJs` suite           | node + browser globals    | `Build/Tests/TypeScript/` |
| `Tests/Acceptance/`         | the Playwright specs         | node globals              | `Build/playwright/`       |
| `Build/playwright/*.ts`     | the Playwright configuration | node globals              | `Build/playwright/`       |

The last two were outside both gates until the toolchain was pointed at them,
and each tool needed a different thing to reach them.

**eslint refuses to lint a file above the base path of its configuration.** The
base path is the directory of the configuration file when eslint found it by
searching upwards, and the directory eslint was *started in* when the file is
named with `--config` (`eslint/lib/config/config-loader.js:534-547`). The `lint`
script therefore changes into the repository root and names the configuration
explicitly, and every `files` pattern in it is relative to the repository root
rather than to `Build/`. A per-object `basePath` does not help — it narrows the
scope of one config object and cannot widen the run — and moving the
configuration to the repository root does not work either: its plugin imports
resolve through `node_modules` directories above *it*, and the only manifest in
this repository sits next to it in `Build/`.

**TypeScript needed the dependency to exist.** A spec imports `@playwright/test`,
which is installed from `Build/playwright/package.json` and deliberately not
from a `node_modules` at the repository root — so nothing is resolvable by
walking upwards from `Tests/Acceptance/`. The run closes that gap with
`NODE_PATH`; the third project closes it with a `paths` mapping, and the
`typecheckJs` suite installs that manifest as well, with
`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` so the several hundred megabytes of browser
binaries stay out of a type check.

One import is deliberately **not** resolved. `ProgressiveEnhancement.spec.ts`
calls `await import('lit')` inside a `page.evaluate()` callback, which the
browser executes and the page's import map resolves — the assertion being that
it resolves at all. Installing `lit` next to the runner would make that check
pass against *our* copy, i.e. hide exactly the drift the spec exists to catch, so
`Tests/Acceptance/pageRealmModules.d.ts` declares the specifier as a shorthand
ambient module instead. Its exports are `any`, which is what this program
truthfully knows about a module the page supplies; the spec reads the result
through explicit casts and asserts on `typeof`. The sources project is unaffected
and still resolves the real `lit`.

Deliberately deferred, and named as gaps rather than hidden: **real-browser**
JavaScript tests (`@web/test-runner` needs a ~700 MB Chrome image and a
hand-written import map), and stylelint 16 — to be added if and when the CSS
grows beyond a single file.

What is *not* deferred is unit testing the logic. `node --test` covers every
module outside `component/` with no runner, no jsdom and no dependency that is
not already in `package.json`, which is what the
[testable-module split](edit-plugin.md#the-testable-module-split) exists for. It
is the `unitJs` suite, and it is why `@types/node` is a devDependency of a build
whose output runs in a browser.

## Layout

The source tree is split by **application type** one level below `TypeScript/`
and `Css/`, and the emitted tree mirrors that split exactly:

```
Build/                                      (already export-ignored)
  package.json  package-lock.json
  tsconfig.json  eslint.config.mjs  esbuild.mjs
  Sources/
    TypeScript/frontend/frontend-edit.ts    entry point
    TypeScript/frontend/documentState.ts    internal, and its own emitted module
    TypeScript/frontend/component/          the two lit elements — the only DOM
    TypeScript/frontend/model/              state, targets, fields, labels, JSON
    TypeScript/frontend/api/                endpoints, payloads, responses, client
    TypeScript/backend/                     empty, a ".gitkeep" only
    Css/frontend/frontend-edit.css
  Tests/
    TypeScript/                             the "unitJs" suite and its two helpers
Resources/Public/
  JavaScript/frontend/**                    17 artifacts, one per source module
  Css/frontend/frontend-edit.css            committed build artifact
Configuration/JavaScriptModules.php
```

The `backend/` directory holds nothing yet, and it exists anyway: the mapping in
`Configuration/JavaScriptModules.php` publishes `frontend/` and not
`JavaScript/`, so a backend module dropped into the tree later cannot reach a
frontend page by accident. That separation has no enforcement behind it — TYPO3
scopes no import-map entry by application type — so the directory is where the
convention is written down.

`frontend-edit.ts` does two things and delegates the rest: it imports the
modules that define the custom elements — which is the whole registration, there
is no initialisation call and no inline script — and it sets
`frontend-edit-loaded` on `<html>` through `documentState.ts`. Every rule in the
stylesheet is scoped to that class. A stylesheet loaded through `f:asset.css`
applies whether or not the module behind `f:asset.module` ran, so without that
gate a page whose module failed to resolve would show edit affordances that
nothing responds to.

The split between `component/` and the rest is not a filing convention. Only
`component/` may touch the DOM; everything else is plain functions and one class
in erasable syntax, which is what lets `node --test` import the sources directly.
→ [The testable-module split](edit-plugin.md#the-testable-module-split)

Three conventions hold this together:

- **One emitted module per source module.** `entryPoints` is every `.ts` file
  below `Sources/TypeScript/`, walked by `modulesIn()`
  (`Build/esbuild.mjs:52-67`), and `outbase` mirrors the tree into
  `Resources/Public/JavaScript/`. Nothing is bundled and nothing is minified,
  which is core's 1:1 mapping rather than the opposite of it. The cost is
  stated below.
- **Shadow-DOM styles live in the TypeScript**, as ``static styles = css`…` ``.
  They cannot come from a `<link>`, and this is what core's own lit components
  do. The build has nothing to do for them.
- **Only page-level, light-DOM CSS is emitted as a file**, from
  `Build/Sources/Css/frontend/*.css`. There is no third mechanism, and no inline
  style block.

Source maps are not committed — inline maps in the dev build only, matching
core's `*.js.map` ignore. The `--dev` flag does nothing else: `minify` is
`false` unconditionally (`Build/esbuild.mjs:75`), so the development and the
committed build differ by the source map and by nothing that could change
behaviour.

### The sources import each other by bare specifier

Not relatively. `documentState.ts` is imported as
`@sbuerk/modern-extbase-frontend-edit/frontend/documentState.js`, never as
`./documentState.js`, and that is load bearing rather than stylistic.

**Only a specifier resolved through the import map gets a cache-busting key.**
For a mapping whose specifier ends in a slash, `resolvePaths()` hands off to
`resolveRecursiveImportMap()` (`ImportMap.php:240-280`), which enumerates every
`.js` file below the mapped directory and emits one map entry per file with
`?bust=` appended (`ImportMap.php:276`). A relative specifier never reaches any
of that: the browser resolves it against the URL of the *importing* module,
which drops the query string. The entry module would then be fetched with a
fresh bust value while its dependencies were fetched at the URLs they had
before, so a deploy could pair a new entry point with a dependency the browser
still holds from the previous release — the worst shape of cache bug, because
each file is individually current and only the combination is wrong. Bundling
hid this by having nothing to pair; an unbundled build has to face it.

Core reached the same conclusion and states it as a rule, in
`14.0/Deprecation-106618-GeneralUtilityresolveBackPath.rst`:

> References to JavaScript modules (ES6 modules) should be managed through
> import maps using module names instead of relative paths.

It also holds itself to it. Grepping the 319 shipped `.js` files below the
sysexts' `Resources/Public/JavaScript/`, excluding the vendored `Contrib/`
trees, finds **no relative import at all** — not one `from './…'` in the whole
of core.

**The cost is that the addressable surface is now 17 specifiers, not one.**
Every module below `frontend/` — `model/json.js`, `api/client.js`, all of them —
is a public entry in the import map that any template on the site may load,
where a bundled build published exactly the one thing `Edit.html` asks for. That
is a real loss of encapsulation and it is accepted knowingly, because the
alternative is either the stale-dependency bug above or giving up 1:1 emit
altogether.

There is no third option, and in particular `exclude` is not one. It is
available on a recursive mapping (`ImportMap.php:294`, applied at
`ImportMap.php:271-275`), but it only suppresses the *bust entry* for the files
it names: the trailing-slash specifier itself stays in the map untouched
(`ImportMap.php:306`), and a trailing-slash entry is resolved by the browser as
a prefix. An excluded module therefore remains loadable at its own specifier and
merely loses its cache-busting key — strictly worse than not excluding it.

## How Fluid loads it

`Resources/Private/Templates/ProfileEdit/Edit.html` is the one template that
does it, and it does it in **one branch only** — the branch that renders an
editable profile. A visitor who is not logged in, or who owns no profile, gets
no module and no stylesheet, because there is nothing on the page for them to
enhance:

```html
<f:asset.css
    identifier="modernExtbaseFrontendEdit"
    href="EXT:modern_extbase_frontend_edit/Resources/Public/Css/frontend/frontend-edit.css"
/>
<f:asset.module identifier="@sbuerk/modern-extbase-frontend-edit/frontend/frontend-edit.js" />
```

The one specifier named here is the entry point. The other sixteen are in the
map as well and no template loads them; the browser fetches them because the
entry point imports them.

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
`USER_INT` is needed for the assets.

**They survive `USER_INT` as well, by a different mechanism**, and that is the
one the edit plugin actually relies on — it is non-cacheable because its markup
carries a per-browser request token and the whole profile document. Assets
collected while the non-cached pass runs are rendered into the placeholders of
the already-cached page by
`PageRenderer::renderJavaScriptAndCssForProcessingOfUncachedContentObjects()`
(`cms-frontend/Classes/Http/RequestHandler.php:300-307`), which re-runs the
whole JavaScript and CSS rendering, import map included. A leftover
`<!-- ###JS_LIBS` marker in the body is what that step failing looks like, and
`ProfileEditPluginTest::theAssetsOfTheEditingSurfaceAreEmitted()` asserts its
absence separately — a missing tag and an unsubstituted placeholder are
different defects.

Two things are deliberately not used:

- **`useNonce`** on `f:asset.css`/`f:asset.script` — deprecated in favour of
  `csp` by Deprecation #100887 (v14.2), and its successor is not passed either.
  That is *not* version neutral, and the next paragraphs say what it costs.
- **The `HeaderAssets`/`FooterAssets` Fluid sections** — deprecated by
  Deprecation #107057 (v14.0), which names `f:asset.script`/`f:asset.css` as the
  replacement.

The rename in Deprecation #100887 came with a changed default, and an earlier
revision of this page missed it. Feature #100887 states it: "The new default is
`true` for external files, that is, static resources, and `false` for inline
content." `resolveCspOption()` implements exactly that — both arguments are
registered with a `null` default (`CssViewHelper.php:81-82`), and when neither
was given it returns whether the asset is an external file
(`CssViewHelper.php:124-137`, `:100-101`). Our `<f:asset.css>` has an `href` and
no `inline`, so on v14.2+ it is collected with `csp => true`. On 13.4 the same
tag is collected with `useNonce => false`: that view helper registers no `csp`
argument at all and gives `useNonce` a default of `false`, so an omitted
argument means "do nothing" there and "collect" here.

Two things follow from that, and neither is a defect. A SHA-256 hash of the
stylesheet is registered with the `DirectiveHashCollection` at render time
(`AssetRenderer.php:116-123`), and the `<link>` gains a `nonce` attribute
(`AssetRenderer.php:162-163`) — the latter only when a `ConsumableNonce` reached
the `PageRenderer` at all, which in the frontend happens only for a site that
configures `contentSecurityPolicies`
(`cms-frontend/Classes/Middleware/ContentSecurityPolicyHeaders.php:70-83`,
`cms-frontend/Classes/Http/RequestHandler.php:101-103`). **The emitted CSP
header does not change either way**: collected hashes are only applied when
`behavior.useHash` is enabled, and that property defaults to `null`, which
Feature #100887 defines as off (`Configuration/Behavior.php:40`).

The honest part is that there is **no version-neutral way to suppress it**.
`csp="0"` does not exist before 14.2, and `useNonce="0"` triggers
`E_USER_DEPRECATED` on v14 from the mere presence of the argument, whatever its
value (`CssViewHelper.php:139-147`) — which this repository's
[strictness policy](../testing/phpunit-configuration.md#strictness-policy) turns
into a failing suite. Suppressing it would therefore require the very
`Core13`/`Core14` split that
[the section above](#consequence-asset-loading-needs-no-core13core14-split)
argues asset loading does not need. The difference is a hash that is computed
and then not used, on one stylesheet, so it is left alone.

Data reaches the component as **attributes on the custom element in the Fluid
template**, not as `JavaScriptModuleInstruction` items and not as an inline
`<script>`. An inline script would need a CSP nonce, and a nonce makes every
page carrying an editable record uncacheable; four `data-` attributes keep the
data in the markup, keep the component testable in isolation, and keep the
refusal path — a malformed attribute is "do not enhance", not an exception.
→ [The Fluid contract](edit-plugin.md#the-fluid-contract-four-attributes)

One operational caveat belongs in an integrator's head, and the unbundled build
sharpened it: the computed import map is cached in `cache.assets`, and for a
`'prefix/' => 'EXT:…/'` mapping the file list is enumerated once and cached.
Adding a new `.js` file in production needs a cache flush; in `Development`
context the bust value is `$GLOBALS['EXEC_TIME']` and it recomputes per request.
A bundled build only ever added a file when a template gained an entry point,
which nobody does by accident. Now every new source module is a new file below
the mapped directory, so "I added a module and the browser reports it missing"
is a cache flush rather than a bug.

## Artifacts are committed, and that makes a gate mandatory

`Resources/Public/JavaScript/**/*.js` and `Resources/Public/Css/**/*.css` are
tracked files — seventeen of the former since the build stopped bundling, where
there used to be one. This is not a preference:

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

| Gate       | What changed                                           | Why                                                                                                                                  |
|------------|--------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| `checkBom` | `! -path "./Build/node_modules/*"` added to the `find` | Running `file` over tens of thousands of npm files takes minutes, and npm packages do ship BOM'd files this repository does not own. |
| `lintPhp`  | `! -path "./Build/node_modules/*"` added to the `find` | `php -l` over the same tree is slow for the same reason, and reports on code that is not ours.                                       |

Both exclusions were verified by planting a file rather than by reading the
`find`. For `lintPhp` that meant an unparseable PHP file in two places: inside
the installed packages the gate stays green, in `Classes/` it turns red. The
distinction matters, because an exclusion that is too broad silently stops the
gate seeing the code it exists for — which is the failure mode `checkBom` was
in for its whole life.

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

| Suite               | Runs                                                                          | Purpose                                                                                                                                              |
|---------------------|-------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| `buildJs`           | `npm ci && npm run build` in `Build/`                                         | Compiles TypeScript and CSS into `Resources/Public/`. Run after every source change, and commit the result.                                          |
| `checkJsBuildClean` | Delete the artifacts, `npm ci && npm run build`, assert `git status` is empty | The gate that makes committed artifacts trustworthy. CI critical.                                                                                    |
| `lintTypescript`    | `npm run lint:fix`, or `lint` when `-n` is given                              | eslint 9 with typescript-eslint and the lit/wc plugins, over every TypeScript tree. Mirrors `cgl`, which fixes by default and only checks with `-n`. |
| `typecheckJs`       | `npm run typecheck`                                                           | `tsc --noEmit` over three projects: the sources, `Build/Tests/TypeScript/` and the acceptance suite.                                                 |
| `unitJs`            | `npm ci && npm test`, which is `node --test`                                  | The logic modules, covered without a browser. Arguments after `--`, e.g. `-- --test-name-pattern 'cancel'`.                                          |
| `npm`               | `npm "$@"` with the working directory set to `Build/`                         | Escape hatch, mirroring the existing `composer` suite: `-s npm -- install --save-dev lit@latest`.                                                    |
| `cleanJs`           | `rm -rf Build/node_modules Build/.cache`                                      | Intermediates only. It never removes `Resources/Public/` — those are committed files.                                                                |

`cleanJs` is also wired into `clean`, next to the existing
`cleanCacheFiles()`/`cleanTestFiles()`. That `checkJsBuildClean` deletes
`Resources/Public/JavaScript` and `Resources/Public/Css` while `cleanJs` refuses
to is not an inconsistency: the gate deletes them *in order to* rebuild them in
the same command, and a `clean` that leaves the working tree with deleted
tracked files would be a trap.

Two properties of these suites differ from every gate documented so far, and the
`-h` output says so:

- **They are core version independent.** They inspect `Build/Sources/`,
  `Build/Tests/` and `Resources/Public/`, never the installed core, so `-t` does
  not change what they do and running them in both halves of the `-t 13` /
  `-t 14` matrix would check the same files twice.
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
    - run: "Build/Scripts/runTests.sh -b docker -s unitJs"
    - run: "Build/Scripts/runTests.sh -b docker -s checkJsBuildClean"
```

Four things about it are deliberate:

- **No matrix.** The suites never touch PHP or the installed core, so repeating
  them across PHP and core versions would check the same TypeScript four times.
  This is how core runs its JavaScript gates as well — a single integrity job
  rather than one per PHP version.
- **No `composerUpdate` step.** Nothing here reads `.Build/`, so the job skips
  the most expensive step in the workflow entirely. It is the only job that
  does.
- **`unitJs` runs in the same job and the same container**, not in a job of its
  own. It shares the `npm ci` with its neighbours, it imports the TypeScript
  sources directly and it knows nothing about PHP or the installed core, so a
  separate job would buy an extra checkout and an extra install for nothing.
- **`checkJsBuildClean` runs last.** Lint, type and test failures are cheaper to
  produce and easier to read than a rebuild diff spread over seventeen files;
  running the expensive, hardest-to-read gate first would bury them.

`-b docker` is passed for the same reason
[every other job passes it](../development/quality-gates.md#why-ci-passes--b-docker),
and has nothing to do with node.

## See also

- [The edit plugin](edit-plugin.md) — what the module these assets ship actually
  does, and the testable-module split behind `unitJs`.
- [Quality gates](../development/quality-gates.md)
- [Development environment](../development/environment.md)
- [Dual core setup](../development/dual-core-setup.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [DTOs and validation](dto-and-validation.md)
