/**
 * Generates the screenshots the rendered documentation embeds — and, in check
 * mode, verifies them.
 *
 * One Playwright test per entry in `Build/playwright/screenshots.config.ts`,
 * reusing the acceptance harness wholesale: the same seeded TYPO3 instance, the
 * same database reset before each shot, the same login fixture and the same
 * page object. A screenshot is therefore taken of the interface a test drives,
 * not of a mock-up that can drift away from it.
 *
 * Named `*.shots.ts` rather than `*.spec.ts` so the acceptance configuration
 * cannot collect it.
 *
 * ## The two modes, and why they are one file
 *
 * `-s screenshotDocumentation` **writes** the images into the tracked tree.
 * `-s checkDocumentationScreenshots` takes the same shots and **compares** them
 * against what is committed instead of overwriting it, and it is a gate.
 *
 * They are one file rather than two because a check that reaches the surface by
 * a different route is a check of the route. Everything that decides what a shot
 * looks like — the login, the reset, the viewport, the device scale factor, the
 * `prepare` steps, the clip, the screenshot options, the encoder settings — has
 * to be identical on both sides or the gate reports differences it invented. The
 * mode branches at the last statement and nowhere else.
 *
 * ## What each mode assumes about the other
 *
 * Generate mode asserts almost nothing: a shot of an element that is not there
 * would be written as a blank image, so the element's existence is checked, and
 * that is all. It has to stay that way. Adding a shot means running the
 * generator first and committing what it produced — there is nothing to compare
 * against yet, and a generator that refused to generate would be unusable.
 */
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import sharp from 'sharp';
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';
import { assertShotIsCurrent } from '../Support/screenshotComparison';
import { orphanedShotImages, unresolvableEmbeds, unusedImages } from '../Support/screenshotWiring';
import { defaults, variants } from '../../../Build/playwright/screenshots.config';

// `__dirname`, not `import.meta.dirname`: the latter makes the file an ES
// module, and the Playwright transform emits CommonJS for the test files it
// collects. `Tests/Acceptance/manifest.ts` resolves its own paths the same way.
const imageRoot = resolve(__dirname, '../../../Documentation/files/images');

/**
 * Set by `runTests.sh -s checkDocumentationScreenshots`.
 *
 * An environment variable rather than a fourth Playwright configuration: the
 * two modes differ in one statement, and a configuration file per statement is
 * how a harness becomes unreadable. The `-s` suite is the interface; nobody is
 * expected to set this by hand.
 */
const checking = process.env.DOCUMENTATION_SCREENSHOTS === 'check';

for (const { shot, scheme, name, output } of variants) {
    // A describe block per variant, because viewport, device scale factor,
    // "javaScriptEnabled" and "colorScheme" are all *context* options: they have
    // to be set before the page exists, and "test.use()" is the only way to do
    // that per test. Setting the viewport on a live page would apply the first
    // but silently ignore the rest, which is exactly the defect this shape
    // prevents - a shot of the server rendered fallback taken with JavaScript
    // enabled looks entirely plausible and shows the wrong thing.
    //
    // "colorScheme" emulates "prefers-color-scheme", which is enough to flip
    // both stylesheets at once: the site package applies its dark palette inside
    // that media query to "body[data-color-scheme='auto']", and "auto" is what
    // this instance renders. No site configuration is touched, and the pinned
    // scheme sites that "PinnedColorScheme.spec.ts" needs are deliberately not
    // used here - the manual illustrates what a *visitor* sees, not how an
    // integrator configured the fixture.
    test.describe(name, (): void => {
        test.use({
            viewport: shot.viewport ?? defaults.viewport,
            deviceScaleFactor: defaults.deviceScaleFactor,
            javaScriptEnabled: shot.javaScriptEnabled ?? true,
            colorScheme: scheme,
        });

        test(checking ? 'is up to date' : 'is generated', async ({ page, loginAs }): Promise<void> => {
            if (shot.as !== null) {
                await loginAs(shot.as);
            }

            const surface = new ProfileEditPage(page);
            await surface.open();
            await shot.prepare?.(surface, page);

            const target = shot.clip === undefined ? null : page.locator(shot.clip);
            if (target !== null) {
                // A shot of an element that is not there would be written as an
                // empty image and reach the manual without anyone noticing.
                await expect(target).toHaveCount(1);
            }

            // `caret` and `animations` are the two defaults that differ between
            // a raw `screenshot()` and `toHaveScreenshot()`: the latter hides the
            // one and disables the other, this took neither. Both were measured
            // rather than argued, and they turned out to be separate faults.
            //
            // `caret` is why the generator was **irreproducible**.
            // `edit-field-rejected` wrote three different files in three runs of
            // one commit; hiding the caret alone made the next three byte
            // identical. Focus returns to a rejected control asynchronously, so
            // the caret is drawn in some runs and not in others.
            //
            // `animations` is why three shots were **wrong**, which is the more
            // interesting half. The 120ms `border-color` transition of #24 is
            // still running when the shutter opens, so `field-open`,
            // `field-rejected` and `record-open` all showed a `Cancel` button
            // caught part way through a fade — a state the surface never rests
            // in and no reader will ever see. Disabling animations finishes a
            // transition instead of photographing it, so the manual shows the
            // settled surface.
            //
            // The generator's own docblock claimed there were no transitions in
            // the stylesheet. That was true when it was written and #24 made it
            // false, and nothing noticed for eight pull requests, which is the
            // argument for the gate that now checks these files.
            const png = await (target ?? page).screenshot({
                type: 'png',
                caret: 'hide',
                animations: 'disabled',
            });
            const file = resolve(imageRoot, output);
            const avif = await sharp(png).avif(defaults.avif).toBuffer();

            if (checking) {
                await assertShotIsCurrent(file, avif, name);

                return;
            }

            await mkdir(dirname(file), { recursive: true });
            await writeFile(file, avif);
        });
    });
}

if (checking) {
    // Declared only in check mode, rather than declared always and skipped.
    // A skipped test is a line in a report that nobody reads as "this did not
    // run", and the generator has a legitimate reason to violate all three of
    // these: adding a shot means generating an image that no chapter embeds yet.
    test.describe('the documentation images', (): void => {
        test('are all produced by a configured shot', (): void => {
            const orphans = orphanedShotImages(variants.map((variant): string => variant.output));

            expect(
                orphans,
                'Images below a generated directory that no shot in "Build/playwright/screenshots.config.ts" '
                + 'produces. A shot was renamed or removed and its image stayed behind; delete it.',
            ).toEqual([]);
        });

        test('are all embedded by a chapter', (): void => {
            expect(
                unusedImages(),
                'Images that no "figure::" or "image::" directive in "Documentation/" embeds. Either a '
                + 'chapter lost its illustration, or the image is dead weight in the repository.',
            ).toEqual([]);
        });

        test('are all that the chapters ask for', (): void => {
            // The renderer only *warns* about an image it cannot resolve and
            // still exits zero, exactly as it does for an unresolved ":ref:",
            // so "-s renderDocumentation" is not a second opinion here.
            expect(
                unresolvableEmbeds(),
                'Directives pointing at a file that does not exist. The rendered page shows a broken '
                + 'image and the renderer does not fail.',
            ).toEqual([]);
        });
    });
}
