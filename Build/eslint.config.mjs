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
 * See "docs/frontend-edit/frontend-assets.md".
 */
import js from '@eslint/js';
import lit from 'eslint-plugin-lit';
import wc from 'eslint-plugin-wc';
import globals from 'globals';
import tseslint from 'typescript-eslint';

/**
 * The house rules, applied to the sources and to the tests alike. Extracted only
 * so the two blocks below cannot drift apart — the lit and web component configs
 * are what differs between them, not the rules.
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
        // The compiled artifacts are generated, and "node_modules" is not ours.
        ignores: [
            'node_modules/**',
        ],
    },
    js.configs.recommended,
    tseslint.configs.recommended,
    {
        files: ['Sources/TypeScript/**/*.ts'],
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
        // The tests run in node and get neither the lit nor the web component
        // rules — they never touch a custom element, which is the point of the
        // modules they cover. They do see the browser globals as well, because
        // the fetch and Response types the client is built on are the browser's.
        files: ['Tests/TypeScript/**/*.ts'],
        languageOptions: {
            globals: { ...globals.nodeBuiltin, ...globals.browser },
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
