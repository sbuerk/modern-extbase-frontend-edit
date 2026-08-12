/**
 * The two geometric promises a field makes.
 *
 * Both were claimed in a comment before they were true, which is the reason this
 * file exists. A stylesheet can state an intention that the box model quietly
 * refuses to honour, and nothing notices until somebody measures — so the two
 * claims that the layout actually rests on are measured here.
 *
 * 1. **A field does not change height when it is edited.** The value and the
 *    control that replaces it are meant to occupy the same box. They did not:
 *    `min-height` applied to the content box while `padding-block` added to it,
 *    so a 36 pixel control was replacing a 48 pixel value and every row moved.
 * 2. **The label sits beside the value, and drops under it when there is no
 *    room.** That is `flex-wrap` responding to the *container*, not a media
 *    query responding to the viewport — the distinction matters because the
 *    plugin is dropped into a column whose width it does not know.
 *
 * Heights are compared to the pixel with a small tolerance, because a browser
 * may resolve a fractional `rem` differently in two boxes without anything being
 * wrong. A reflow worth catching was twelve pixels.
 */
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';

/** Sub-pixel rounding is not a reflow. The defect this guards against was 12px. */
const TOLERANCE = 2;

test.describe('Field layout', (): void => {
    test('a field keeps its height when it is switched into edit', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const field = surface.field('profile', 'firstname');
        const before = await field.boundingBox();
        await surface.startFieldEdit('profile', 'firstname');
        const after = await field.boundingBox();

        expect(before?.height ?? 0).toBeGreaterThan(0);
        expect(
            Math.abs((after?.height ?? 0) - (before?.height ?? 0)),
            'the row moved when the control replaced the value',
        ).toBeLessThanOrEqual(TOLERANCE);
    });

    test('the label sits beside the value, and under it in a narrow column', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const field = surface.field('profile', 'firstname');
        const label = field.locator('.field-label');
        const body = field.locator('.field-body');

        // Wide: one row, so both start at the same height.
        const wideLabel = await label.boundingBox();
        const wideBody = await body.boundingBox();
        expect(
            Math.abs((wideBody?.y ?? 0) - (wideLabel?.y ?? 0)),
            'label and value are not on the same row',
        ).toBeLessThanOrEqual(TOLERANCE);

        // Narrow: the body wraps below the label. The viewport is what is
        // resized here, but what does the wrapping is the width of the element —
        // there is no media query anywhere in this extension's CSS.
        await page.setViewportSize({ width: 380, height: 800 });
        const narrowLabel = await label.boundingBox();
        const narrowBody = await body.boundingBox();
        expect(
            narrowBody?.y ?? 0,
            'the value did not wrap under its label in a narrow column',
        ).toBeGreaterThan((narrowLabel?.y ?? 0) + (narrowLabel?.height ?? 0) - TOLERANCE);
    });
});
