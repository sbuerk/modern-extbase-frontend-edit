/**
 * Configuration of the documentation screenshot generator.
 *
 * Separate from `playwright.config.ts` for two reasons, and both of them are
 * about not contaminating the acceptance suite. `testMatch` collects
 * `*.shots.ts` and never `*.spec.ts`, so neither configuration can pick up the
 * other's files whatever is added later. And the artifact paths are the
 * generator's own, because `startAcceptanceInstance()` deletes the acceptance
 * report and result directories at the start of every run.
 *
 * `forbidOnly` is off here. This is a generator, not a gate: regenerating one
 * shot while writing a chapter is the normal way to use it, and the `--grep`
 * of a shot name is the supported way to do it.
 */
import { defineConfig } from '@playwright/test';
import { artifactPath, manifest } from '../../Tests/Acceptance/manifest';

export default defineConfig({
    testDir: '../../Tests/Acceptance/Screenshots',
    testMatch: '**/*.shots.ts',

    timeout: 30_000,
    expect: {
        timeout: 10_000,
    },

    // One worker, for the same reason the acceptance suite uses one: the shots
    // share a database and each is taken after a reset.
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: false,

    reporter: [['list']],
    outputDir: artifactPath('playwright-screenshot-results'),

    use: {
        baseURL: manifest.baseUrl,
        trace: 'off',
        screenshot: 'off',
        video: 'off',
    },
});
