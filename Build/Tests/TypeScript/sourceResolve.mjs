/**
 * Makes the sources importable by a plain node process.
 *
 * The modules under `Build/Sources/TypeScript/` import each other with the `.js`
 * extension, which is what the emitted ESM has to say and what `tsc` and esbuild
 * both expect. Node runs the `.ts` file directly — it strips the types since
 * node 22 — but it does **not** rewrite the specifier, so `./types.js` next to a
 * `types.ts` is a plain "module not found".
 *
 * This hook rewrites exactly that: a specifier ending in `.js` is retried as
 * `.ts`, for the two shapes the sources use.
 *
 * The sources import each other by the bare specifier the TYPO3 import map
 * resolves in the browser, so that shape is mapped back onto the source tree
 * here — the same mapping `paths` performs for `tsc`, and `--experimental-*`
 * import map support in node is not stable enough to rely on. The tests
 * themselves still import relatively, which is the second shape.
 *
 * Every other bare specifier is untouched, so a package still resolves the
 * normal way and nothing outside the sources is affected.
 *
 * `registerHooks()` is the synchronous, in-thread form of the loader API (node
 * 22.15+). It needs no worker and no second file, which is why the whole test
 * setup is one `--import` and nothing else — see the `test` script in
 * `Build/package.json`.
 */
import { registerHooks } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const sources = resolve(dirname(fileURLToPath(import.meta.url)), '../../Sources/TypeScript');
const prefix = '@sbuerk/modern-extbase-frontend-edit/';

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier.startsWith(prefix) && specifier.endsWith('.js')) {
            const path = `${resolve(sources, specifier.slice(prefix.length)).slice(0, -3)}.ts`;

            return { url: pathToFileURL(path).href, shortCircuit: true };
        }

        if ((specifier.startsWith('./') || specifier.startsWith('../')) && specifier.endsWith('.js')) {
            return nextResolve(`${specifier.slice(0, -3)}.ts`, context);
        }

        return nextResolve(specifier, context);
    },
});
