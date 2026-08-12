/**
 * Makes the sources importable by a plain node process.
 *
 * The modules under `Build/Sources/TypeScript/` import each other with the `.js`
 * extension, which is what the emitted ESM has to say and what `tsc` and esbuild
 * both expect. Node runs the `.ts` file directly — it strips the types since
 * node 22 — but it does **not** rewrite the specifier, so `./types.js` next to a
 * `types.ts` is a plain "module not found".
 *
 * This hook rewrites exactly that: a *relative* specifier ending in `.js` is
 * retried as `.ts`. Bare specifiers are untouched, so a package still resolves
 * the normal way, and nothing outside the sources is affected.
 *
 * `registerHooks()` is the synchronous, in-thread form of the loader API (node
 * 22.15+). It needs no worker and no second file, which is why the whole test
 * setup is one `--import` and nothing else — see the `test` script in
 * `Build/package.json`.
 */
import { registerHooks } from 'node:module';

registerHooks({
    resolve(specifier, context, nextResolve) {
        if ((specifier.startsWith('./') || specifier.startsWith('../')) && specifier.endsWith('.js')) {
            return nextResolve(`${specifier.slice(0, -3)}.ts`, context);
        }

        return nextResolve(specifier, context);
    },
});
