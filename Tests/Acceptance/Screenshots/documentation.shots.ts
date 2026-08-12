/**
 * Generates the screenshots the rendered documentation embeds.
 *
 * One Playwright test per entry in `Build/playwright/screenshots.config.ts`,
 * reusing the acceptance harness wholesale: the same seeded TYPO3 instance, the
 * same database reset before each shot, the same login fixture and the same
 * page object. A screenshot is therefore taken of the interface a test drives,
 * not of a mock-up that can drift away from it.
 *
 * It is not a test suite. Nothing here asserts anything about the pixels; the
 * files are written into the tracked tree and reviewed by a person. The one
 * assertion is that the element being photographed exists, because a shot of a
 * missing element would otherwise be written as a blank image and land in the
 * manual unnoticed.
 *
 * Named `*.shots.ts` rather than `*.spec.ts` so the acceptance configuration
 * cannot collect it.
 */
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import sharp from 'sharp';
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';
import { defaults, shots } from '../../../Build/playwright/screenshots.config';

// `__dirname`, not `import.meta.dirname`: the latter makes the file an ES
// module, and the Playwright transform emits CommonJS for the test files it
// collects. `Tests/Acceptance/manifest.ts` resolves its own paths the same way.
const imageRoot = resolve(__dirname, '../../../Documentation/files/images');

for (const shot of shots) {
    // A describe block per shot, because viewport, device scale factor and
    // "javaScriptEnabled" are *context* options: they have to be set before the
    // page exists, and "test.use()" is the only way to do that per test. Setting
    // the viewport on a live page would apply the first but silently ignore the
    // other two, which is exactly the defect this shape prevents - a shot of the
    // server rendered fallback taken with JavaScript enabled looks entirely
    // plausible and shows the wrong thing.
    test.describe(shot.name, (): void => {
        test.use({
            viewport: shot.viewport ?? defaults.viewport,
            deviceScaleFactor: defaults.deviceScaleFactor,
            javaScriptEnabled: shot.javaScriptEnabled ?? true,
        });

        test('is generated', async ({ page, loginAs }): Promise<void> => {
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

            const png = await (target ?? page).screenshot({ type: 'png' });
            const file = resolve(imageRoot, shot.output);
            await mkdir(dirname(file), { recursive: true });
            await writeFile(file, await sharp(png).avif(defaults.avif).toBuffer());
        });
    });
}
