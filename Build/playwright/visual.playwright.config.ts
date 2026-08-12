/**
 * Configuration of the visual regression suite.
 *
 * A third Playwright configuration, and a third file naming convention, for the
 * same reason the screenshot generator has its own: `testMatch` is what keeps
 * three suites that share one harness from collecting each other's files.
 * `*.spec.ts` is the acceptance suite, `*.shots.ts` the documentation generator,
 * `*.visual.ts` this.
 *
 * ## Why this suite exists now and did not before
 *
 * It was refused three times while the surface was being designed, and the
 * reason is worth keeping: a visual baseline freezes an appearance, and freezing
 * one nobody is happy with turns every deliberate improvement into a wall of red
 * diffs to approve. A reviewer who approves a hundred of those stops looking at
 * them, and the suite then costs time and catches nothing. It is worth having
 * once the design has stopped moving, which is what it is guarding.
 *
 * ## Why the shots are clipped to components
 *
 * The obvious baseline is a full page, and it is the wrong one twice over: a
 * 2000 pixel image is a large binary in the repository, and *any* change
 * anywhere in the surface fails it, so the diff never says what broke. Every
 * baseline here is one locator — a field, a header, the image row — so a failure
 * names the component and the image is a few kilobytes.
 *
 * ## Determinism
 *
 * The same three guarantees the documentation generator relies on: the fixture
 * profile is fixed, the database is restored from a snapshot before every test,
 * and the fonts come from the Playwright image, which is why this cannot be run
 * on a host. Two more come from `toHaveScreenshot()` itself, which disables
 * animations and hides the caret by default.
 *
 * `deviceScaleFactor` is deliberately left at 1, unlike the documentation shots.
 * A 2× baseline is four times the bytes and four times the antialiased edges
 * that can differ between two machines, and nothing here is read by a human.
 */
import { defineConfig } from '@playwright/test';
import { artifactPath, manifest } from '../../Tests/Acceptance/manifest';

export default defineConfig({
    testDir: '../../Tests/Acceptance/Visual',
    testMatch: '**/*.visual.ts',

    timeout: 30_000,
    expect: {
        timeout: 10_000,
        toHaveScreenshot: {
            /*
             * Antialiasing is not a regression. The tolerance is a pixel count
             * rather than a ratio so that it means the same thing for a 40 pixel
             * tall field as for a 300 pixel one — a ratio would be nearly free on
             * the large shots and unusably tight on the small ones.
             *
             * 60 pixels is roughly the edge of two glyphs, and the headroom was
             * measured rather than estimated: raising
             * `--frontend-edit-border-width` from 1px to 2px fails **all seven**
             * baselines, and the smallest of them differs by 188 pixels. A
             * change small enough to slip through this is smaller than a border.
             */
            maxDiffPixels: 60,
        },
    },

    /*
     * Baselines live next to the specs and are committed, so a change to the
     * surface arrives in a pull request as an image diff a reviewer can look at.
     * `Tests/` is `export-ignore`d, so none of it reaches the composer package.
     *
     * The platform is pinned out of the path on purpose. Playwright defaults to
     * `{platform}` in the name, which would invite a second set of baselines
     * from a host run — and a host run is exactly what has no shared fonts. One
     * set, generated in the container, is the point.
     */
    snapshotPathTemplate: '{testDir}/__baselines__/{testFileName}/{arg}{ext}',

    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: true,

    reporter: [
        ['list'],
        ['html', { outputFolder: artifactPath('playwright-visual-reports'), open: 'never' }],
    ],
    outputDir: artifactPath('playwright-visual-results'),

    use: {
        baseURL: manifest.baseUrl,
        // A failure here is an image diff, and the report carries it. A trace
        // would only repeat what the three attached PNGs already show.
        trace: 'off',
        screenshot: 'off',
        video: 'off',
        viewport: { width: 1280, height: 900 },
        deviceScaleFactor: 1,
    },
});
