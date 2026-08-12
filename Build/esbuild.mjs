/**
 * The whole build: TypeScript to ESM, per-entry bundling of our own modules, and
 * the page level stylesheet.
 *
 *   node esbuild.mjs          production build, what is committed
 *   node esbuild.mjs --dev    same, unminified and with an inline source map
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
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const buildRoot = dirname(fileURLToPath(import.meta.url));
const extensionRoot = resolve(buildRoot, '..');
const development = process.argv.slice(2).includes('--dev');

/**
 * Specifiers that TYPO3 resolves through the import map and that must therefore
 * survive as bare imports in the emitted module.
 *
 * "lit" and its packages are declared in EXT:core's own
 * "Configuration/JavaScriptModules.php", so a single 'dependencies' => ['core']
 * in our module configuration reaches them from the frontend. Bundling a second
 * copy is a correctness bug, not a payload question: two copies mean two
 * ReactiveElement registries, "instanceof" checks that fail across them, and a
 * "customElements.define()" that throws on the second registration.
 *
 * "@typo3/*" is core's own module namespace and is mapped the same way.
 */
const external = [
    '@lit/*',
    '@lit-labs/*',
    '@typo3/*',
    'lit',
    'lit/*',
    'lit-element',
    'lit-element/*',
    'lit-html',
    'lit-html/*',
];

/**
 * The browser floor of the import map mechanism itself, which TYPO3 no longer
 * polyfills on either v13.4 or v14.3. Targeting anything older would emit
 * transpiled output for browsers that cannot resolve the module in the first
 * place.
 */
const target = ['chrome89', 'firefox108', 'safari16.4'];

const shared = {
    bundle: true,
    target,
    minify: !development,
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

// One entry point per import-map specifier. Modules an entry imports are bundled
// into it and get no specifier of their own — the addressable surface stays
// exactly as large as the set of things a Fluid template may load.
await build({
    ...shared,
    entryPoints: [resolve(buildRoot, 'Sources/TypeScript/frontend-edit.ts')],
    outdir: resolve(extensionRoot, 'Resources/Public/JavaScript'),
    tsconfig: resolve(buildRoot, 'tsconfig.json'),
    format: 'esm',
    platform: 'browser',
    external,
});

// Only page level, light DOM CSS is emitted as a file. Shadow DOM styles live in
// the TypeScript as "static styles = css`…`" and never reach this build.
await build({
    ...shared,
    entryPoints: [resolve(buildRoot, 'Sources/Css/frontend-edit.css')],
    outdir: resolve(extensionRoot, 'Resources/Public/Css'),
});
