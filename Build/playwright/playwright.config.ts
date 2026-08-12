/**
 * Configuration of the acceptance suite.
 *
 * The specs live in `Tests/Acceptance/` next to `Tests/Unit/` and
 * `Tests/Functional/`, because they are tests; the configuration lives here
 * next to `Build/phpunit/`, `Build/phpstan/` and `Build/php-cs-fixer/`, because
 * that is where every other tool's configuration lives.
 *
 * ## Why this has a `package.json` of its own
 *
 * `Build/package.json` is the *asset* build: it compiles
 * `Build/Sources/TypeScript/` into `Resources/Public/JavaScript/`, and those
 * artifacts are committed and shipped. Sharing one manifest with it would mean
 * a Playwright bump forces a rebuild and a diff of shipped assets, and it would
 * pull the browser drivers into every `npm ci` of the six node suites. Two
 * manifests, two lockfiles, no shared dependency.
 *
 * ## Why serial, and why nothing is retried
 *
 * The specs share one TYPO3 instance, one SQLite file and one editable profile,
 * and every one of them is reset from a snapshot before it starts - see
 * `Tests/Acceptance/fixtures.ts`. That reset is what makes an assertion about
 * *persistence* possible at all, and it is only correct while a single worker
 * owns the database.
 *
 * TYPO3 core runs its own Playwright suite with `retries: 2` and does not reset
 * between specs. Both halves of that trade are declined here: a browser test
 * that only passes on the second attempt is a defect that has been hidden, and
 * without a reset "the value is still there after a reload" cannot be told apart
 * from "some earlier spec wrote it".
 */
import { defineConfig } from '@playwright/test';
import { manifest, artifactPath } from '../../Tests/Acceptance/manifest';

export default defineConfig({
    testDir: '../../Tests/Acceptance',
    testMatch: '**/*.spec.ts',

    // A page load, a save and a reload against a container-hosted TYPO3. The
    // default 30s is generous for that and tight enough that a hung request
    // fails the run rather than the job timeout.
    timeout: 30_000,
    expect: {
        timeout: 10_000,
    },

    fullyParallel: false,
    workers: 1,
    retries: 0,
    // `test.only` left in a spec would silently reduce the suite to one test.
    forbidOnly: true,

    reporter: [
        ['list'],
        ['html', { outputFolder: artifactPath('playwright-reports'), open: 'never' }],
    ],
    // Traces, screenshots and the report all land below
    // `.Build/Web/typo3temp/var/tests/`, which is the one path
    // `runTests.sh -s cleanTests` removes and the one CI uploads on failure.
    outputDir: artifactPath('playwright-results'),

    use: {
        baseURL: manifest.baseUrl,
        // Retained rather than "on-first-retry", because there are no retries:
        // a failure has to be debuggable from the first and only run.
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        // Deliberately off. The trace carries a DOM snapshot per action, which
        // is what a failing custom element has to be read from; a video of a
        // shadow root shows nothing the trace does not.
        video: 'off',
    },
});
