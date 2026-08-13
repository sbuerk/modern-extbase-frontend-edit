/**
 * What the editing surface looks like, guarded one component at a time.
 *
 * Every other spec in this directory asserts a **contract** — that a hidden
 * label keeps its accessible name, that a field does not change height when it
 * is edited, that a heading follows its record. Those survive a restyle, which
 * is why they are written the way they are.
 *
 * This file asserts the opposite: the pixels, and nothing about why they are
 * what they are. It exists because three real defects of the styling round were
 * invisible in the CSS and obvious in an image — an `Edit` button a thousand
 * pixels from its value, a red error ring drowned by a blue focus ring, and a
 * field spending 76 pixels to show twenty pixels of text. None of them would
 * have been caught by an assertion anybody would have thought to write.
 *
 * ## Reading a failure
 *
 * A failure is three PNGs in the report: expected, actual, and the diff. Look at
 * the diff before touching anything, because there are exactly two cases and
 * they are not distinguishable from the exit code:
 *
 * - **The change was intended.** Re-record the baseline and let the image diff
 *   be reviewed in the pull request:
 *   `Build/Scripts/runTests.sh -s visualRegression -- --update-snapshots`
 * - **The change was not intended.** Fix the CSS. A baseline is not a nuisance
 *   to be silenced, and re-recording without looking is exactly the failure mode
 *   that makes a suite like this worthless.
 *
 * ## What is deliberately not here
 *
 * No full page baseline. A 2000 pixel image is a large binary that fails on any
 * change anywhere and names none of them; every shot below is one component.
 *
 * No *full* dark scheme set. Three of the seven shots are taken again in dark and
 * the other four are not, which is a deliberate middle rather than an oversight —
 * see the dark block at the end of this file for which three and why.
 */
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';

test.describe('The editing surface', (): void => {
    test('a field at rest', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // The label column, the value, and the action that belongs to it — the
        // row layout the whole surface is built from.
        await expect(surface.field('profile', 'firstname')).toHaveScreenshot('field-at-rest.png');
    });

    test('a field being edited', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();
        await surface.startFieldEdit('profile', 'firstname');

        // Also the baseline that would catch the control and the value drifting
        // apart in height again, which no CSS review noticed twice.
        await expect(surface.field('profile', 'firstname')).toHaveScreenshot('field-being-edited.png');
    });

    test('a field the server rejected', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();
        await surface.startFieldEdit('profile', 'shortname');
        await surface.type('profile', 'shortname', '');
        await surface.applyField('profile', 'shortname');

        // One colour, not two: the invalid ring and the focus ring are drawn in
        // the danger colour together, which is what makes the field read as
        // wrong rather than as focused.
        await expect(surface.field('profile', 'shortname')).toHaveScreenshot('field-rejected.png');
    });

    test('the header of a child record', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Its identity and the toolbar that acts on it, including the four
        // icon-only buttons and the disabled `Move up` of the first row.
        await expect(surface.childRow('address:2').locator('.frontend-edit-child-header'))
            .toHaveScreenshot('child-header.png');
    });

    test('the header of a hidden child record', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // The one row that carries the state badge, and the one that draws the
        // open eye rather than the struck-through one.
        await expect(surface.childRow('address:4').locator('.frontend-edit-child-header'))
            .toHaveScreenshot('child-header-hidden.png');
    });

    test('the image row without a stored image', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // The file picker and the destructive button beside it, the latter
        // disabled because there is nothing to remove.
        await expect(surface.imageElement).toHaveScreenshot('image-row-empty.png');
    });

    test('a field in a narrow column', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();
        await page.setViewportSize({ width: 380, height: 800 });

        // The wrapped layout, which is what a plugin in a narrow column gets and
        // what no screenshot in the manual shows.
        await expect(surface.field('profile', 'firstname')).toHaveScreenshot('field-narrow.png');
    });

});

/**
 * The dark scheme, which until now was drawn by two stylesheets and looked at by
 * nobody.
 *
 * ## How it is reached
 *
 * `colorScheme: 'dark'` emulates `prefers-color-scheme`, and both stylesheets
 * key off that: the site package applies its dark palette to
 * `body[data-color-scheme="auto"]` inside the media query, and the instance
 * renders `auto` because that is the default of its `devSite.colorScheme`
 * setting. So one context option flips the page **and** the surface, and no
 * instance configuration is touched.
 *
 * A site that *pins* `light` or `dark` is therefore not covered here. That is
 * the setting's other purpose and it has no baseline.
 *
 * ## Why three and not seven
 *
 * A second full set doubles what a restyle has to re-record, and that cost is
 * what kept the dark scheme unpinned until now. Three shots buy most of the
 * signal for less than half the price, chosen by where a dark palette actually
 * goes wrong:
 *
 * - **A field at rest** is the base contrast: text against surface against page.
 *   If the eight dark tokens drift apart, this is where it shows first.
 * - **A rejected field** is the riskiest single state. The danger colour has to
 *   stay legible against a dark surface *and* stay distinguishable from the
 *   focus ring, and the light scheme already shipped that defect once.
 * - **A child header** carries the icon-only buttons, the state badge and a
 *   border — everything that is drawn in `currentColor` or in a border token,
 *   which is what a scheme swap is most likely to lose.
 *
 * The four that are not taken again — the field being edited, the narrow column,
 * the empty image row — differ from their light counterparts only in colours the
 * three above already cover.
 *
 * ## What this still does not assert
 *
 * That the dark scheme is *legible*. It pins that it has not **changed**.
 * Contrast ratios remain a person's judgement, exactly as in light.
 */
test.describe('The editing surface in the dark scheme', (): void => {
    test.use({ colorScheme: 'dark' });

    test('a field at rest', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await expect(surface.field('profile', 'firstname')).toHaveScreenshot('field-at-rest-dark.png');
    });

    test('a field the server rejected', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();
        await surface.startFieldEdit('profile', 'shortname');
        await surface.type('profile', 'shortname', '');
        await surface.applyField('profile', 'shortname');

        await expect(surface.field('profile', 'shortname')).toHaveScreenshot('field-rejected-dark.png');
    });

    test('the header of a child record', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await expect(surface.childRow('address:3').locator('.frontend-edit-child-header'))
            .toHaveScreenshot('child-header-dark.png');
    });
});
