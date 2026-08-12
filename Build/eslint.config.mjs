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
        rules: {
            '@typescript-eslint/explicit-function-return-type': 'error',
            '@typescript-eslint/consistent-type-imports': 'error',
            eqeqeq: 'error',
            'no-console': 'error',
            'prefer-const': 'error',
        },
    },
    {
        // The build and lint configuration themselves run in node, not a browser.
        files: ['*.mjs'],
        languageOptions: {
            globals: globals.nodeBuiltin,
        },
    },
);
