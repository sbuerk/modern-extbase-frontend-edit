/**
 * Modules a spec imports **inside the page**, which this program cannot resolve
 * and must not pretend to.
 *
 * `ProgressiveEnhancement.spec.ts` calls `await import('lit')` inside a
 * `page.evaluate()` callback. That callback is serialised and executed in the
 * browser, where the specifier is resolved by the import map TYPO3 renders — and
 * that resolution is the assertion: it is what a lit major bump in core has to
 * trip over. The import never happens in node.
 *
 * TypeScript does not know that. It sees a bare specifier in a source file of
 * this project and reports TS2307, so the specifier has to be declared. The two
 * obvious ways of declaring it are both wrong:
 *
 * - installing `lit` in `Build/playwright/package.json` would resolve the import
 *   against **our** copy, so the spec would type check against a version the
 *   page never serves — the drift it exists to catch made invisible;
 * - mapping it through `paths` to `Build/node_modules/lit` does the same thing
 *   with an extra indirection.
 *
 * A shorthand ambient declaration types every export as `any`, which is exactly
 * what is true here: this program knows nothing about the module, the page
 * supplies it, and the spec already reads the result through explicit casts and
 * asserts on `typeof` rather than on a type.
 */
declare module 'lit';
