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

    /*
     * Generous, because the cost here is not the browser and raising it is not
     * papering over a hang. A shot clipped to the whole editing surface is about
     * 1280 by 3000 CSS pixels, taken at twice that, and encoding six megapixels
     * to AVIF at `effort: 6` is where the seconds go — `edit-record-open` spends
     * roughly 37 of them and `edit-owner-idle` 25, against browser work measured
     * in hundreds of milliseconds.
     *
     * At 30 seconds the two full surface shots sat on either side of the limit,
     * so growing the surface by one line of padding turned a slow generator into
     * a failing one. That is a bad trade for a tool that writes files for a
     * person to look at: it is not a gate, nothing downstream waits on it, and a
     * timeout here can only ever cost a rerun.
     */
    timeout: 180_000,
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
