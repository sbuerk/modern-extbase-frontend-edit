/**
 * eslint 9 flat configuration.
 *
 *   Build/Scripts/runTests.sh -s lintTypescript        fix in place
 *   Build/Scripts/runTests.sh -s lintTypescript -n     check only, as CI does
 *
 * Type aware linting is deliberately not enabled. "tsc --noEmit" is the type gate
 * and runs as its own suite, so the rules here are the ones a compiler does not
 * have: the lit and web component plugins catch legacy lit imports, reflected
 * native attributes, listeners without teardown and constructor parameters on a
 * custom element — none of which is a type error.
 *
 * ## Every path below is relative to the repository root, not to this file
 *
 * eslint refuses to lint a file above the base path of its configuration, and
 * the acceptance specs live in "Tests/Acceptance/" while this file lives in
 * "Build/" — so a configuration rooted here could never reach them. The base
 * path is the directory eslint was started in **whenever the configuration is
 * named with "--config"**, and the directory of the configuration file only when
 * it was found by searching upwards (eslint 9.39,
 * "lib/config/config-loader.js:534-547").
 *
 * The "lint" script of "Build/package.json" therefore changes into the
 * repository root and names this file explicitly. Moving the file up there
 * instead would have been the obvious alternative and does not work: its plugin
 * imports resolve through "node_modules" directories above *it*, and the only
 * manifest in this repository is the one next to it.
 *
 * See "docs/frontend-edit/frontend-assets.md".
 */
import js from '@eslint/js';
import lit from 'eslint-plugin-lit';
import wc from 'eslint-plugin-wc';
import globals from 'globals';
import tseslint from 'typescript-eslint';

/**
 * The house rules, applied to the sources and to every test tree alike.
 * Extracted only so the blocks below cannot drift apart — what differs between
 * them is the globals and the plugins, not the rules.
 */
const houseRules = {
    '@typescript-eslint/explicit-function-return-type': 'error',
    '@typescript-eslint/consistent-type-imports': 'error',
    eqeqeq: 'error',
    'no-console': 'error',
    'prefer-const': 'error',
};

export default tseslint.config(
    {
        // "node_modules" is not ours — both of them, since the acceptance runner
        // is installed from a manifest of its own. The compiled artifacts under
        // "Resources/Public/" are generated, and the vendor tree is not linted
        // for the same reason it is not type checked.
        ignores: [
            '**/node_modules/**',
            '.Build/**',
            'Resources/Public/**',
        ],
    },
    js.configs.recommended,
    tseslint.configs.recommended,
    {
        files: ['Build/Sources/TypeScript/**/*.ts'],
        extends: [
            lit.configs['flat/recommended'],
            wc.configs['flat/recommended'],
        ],
        languageOptions: {
            globals: globals.browser,
        },
        rules: houseRules,
    },
    {
        // The unit tests run in node and get neither the lit nor the web
        // component rules — they never touch a custom element, which is the
        // point of the modules they cover. They do see the browser globals as
        // well, because the fetch and Response types the client is built on are
        // the browser's.
        files: ['Build/Tests/TypeScript/**/*.ts'],
        languageOptions: {
            globals: { ...globals.nodeBuiltin, ...globals.browser },
        },
        rules: houseRules,
    },
    {
        // The acceptance specs and the Playwright configuration that runs them,
        // which are one island across two directories: the specs are tests and
        // sit next to "Tests/Unit/" and "Tests/Functional/", the configuration
        // is configuration and sits next to "Build/phpunit/".
        //
        // Node globals only. A spec reaches into the page through
        // "page.evaluate()", and the callback it passes is serialised — the
        // browser globals it may use inside are not in scope at this end, so
        // granting them here would only hide a spec that used one outside.
        files: ['Tests/Acceptance/**/*.ts', 'Build/playwright/*.ts'],
        languageOptions: {
            globals: globals.nodeBuiltin,
        },
        rules: houseRules,
    },
    {
        // The build and lint configuration and the test resolve hook run in node,
        // not a browser.
        files: ['**/*.mjs'],
        languageOptions: {
            globals: globals.nodeBuiltin,
        },
    },
);
