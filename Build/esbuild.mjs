/**
 * The whole build: TypeScript to ESM, one emitted module per source module, and
 * the page level stylesheet.
 *
 *   node esbuild.mjs          the build, and what is committed
 *   node esbuild.mjs --dev    same, with an inline source map
 *
 * Called through "Build/Scripts/runTests.sh -s buildJs", which runs it in a
 * container so nothing has to be installed on the host.
 *
 * It does not type check — esbuild never does. "npm run typecheck" is the type
 * gate and runs as its own suite.
 *
 * See "docs/frontend-edit/frontend-assets.md".
 */
import { build } from 'esbuild';
import { readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const buildRoot = dirname(fileURLToPath(import.meta.url));
const extensionRoot = resolve(buildRoot, '..');
const development = process.argv.slice(2).includes('--dev');

/**
 * Nothing is bundled here, so esbuild resolves no import at all and there is no
 * "external" list to keep: every specifier survives into the emitted module
 * exactly as written, and the browser resolves all of them through the TYPO3
 * import map.
 *
 * That is what keeps `lit` borrowed rather than shipped. `lit` and its packages
 * are declared in EXT:core's own "Configuration/JavaScriptModules.php", so the
 * single 'dependencies' => ['core'] in our module configuration reaches them
 * from a frontend page. Shipping a second copy would be a correctness bug
 * rather than a payload question: two copies mean two ReactiveElement
 * registries, "instanceof" checks that fail across them, and a
 * "customElements.define()" that throws on the second registration.
 */

/**
 * The browser floor of the import map mechanism itself, which TYPO3 no longer
 * polyfills on either v13.4 or v14.3. Targeting anything older would emit
 * transpiled output for browsers that cannot resolve the module in the first
 * place.
 */
const target = ['chrome89', 'firefox108', 'safari16.4'];

/**
 * Every TypeScript module below a source directory, so that each one is emitted
 * as its own file rather than being pulled into an entry point.
 */
const modulesIn = (directory) => {
    const entries = [];
    const walk = (current) => {
        for (const entry of readdirSync(current, { withFileTypes: true })) {
            const path = join(current, entry.name);
            if (entry.isDirectory()) {
                walk(path);
            } else if (entry.name.endsWith('.ts')) {
                entries.push(path);
            }
        }
    };
    walk(directory);

    return entries;
};

const shared = {
    target,
    // Never minified. The emitted modules are meant to be opened and read, which
    // is the point of a proof of concept - and unlike core, which ships one
    // minified file per module, nothing here is large enough for the size to be
    // worth the loss.
    minify: false,
    // Source maps are never committed. The development build carries an inline
    // one instead, so no ".map" file exists to be ignored or shipped.
    sourcemap: development ? 'inline' : false,
    legalComments: 'none',
    logLevel: 'info',
    banner: {
        js: '/* Generated from Build/Sources/TypeScript — do not edit. */',
        css: '/* Generated from Build/Sources/Css — do not edit. */',
    },
};

// One emitted module per source module, with the source tree mirrored below
// "Resources/Public/JavaScript" by "outbase". Nothing is bundled: every import
// survives as written and is resolved in the browser by the TYPO3 import map.
//
// That is why the sources import each other by their bare specifier rather than
// relatively. "ImportMap::resolveRecursiveImportMap()" enumerates the files
// below a trailing slash mapping and gives each one a "?bust=" key, and only
// specifiers that go through the map get it - a relative specifier resolves
// against the URL of the importing module and drops the query string, so a
// deploy could pair a fresh entry module with a stale cached dependency. Core
// has no relative import in any shipped module for the same reason.
//
// The cost is that all of them become addressable, not just the entry point.
// There is no way to have one without the other: "exclude" on the mapping only
// suppresses the bust entry, while the prefix still resolves client side.
await build({
    ...shared,
    bundle: false,
    entryPoints: modulesIn(resolve(buildRoot, 'Sources/TypeScript')),
    outbase: resolve(buildRoot, 'Sources/TypeScript'),
    outdir: resolve(extensionRoot, 'Resources/Public/JavaScript'),
    tsconfig: resolve(buildRoot, 'tsconfig.json'),
    format: 'esm',
    platform: 'browser',
});

// Only page level, light DOM CSS is emitted as a file. Shadow DOM styles live in
// the TypeScript as "static styles = css`…`" and never reach this build.
await build({
    ...shared,
    bundle: true,
    entryPoints: [resolve(buildRoot, 'Sources/Css/frontend/frontend-edit.css')],
    outbase: resolve(buildRoot, 'Sources/Css'),
    outdir: resolve(extensionRoot, 'Resources/Public/Css'),
});
