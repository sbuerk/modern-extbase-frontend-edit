/**
 * Whether the ring that says "this control has focus" is visible in both colour
 * schemes.
 *
 * ## Why the page and not the control
 *
 * The ring is an `outline` with `outline-offset: 2px`, so it does not touch the
 * control it belongs to. The two pixel gap shows whatever is painted *behind*
 * the control, and so does everything outside the ring — which means the colour
 * the ring has to be distinguishable from is the **page**, on both of its sides,
 * and not the control's own fill. Measuring it against the fill would be
 * measuring two colours that never touch.
 *
 * WCAG 2.2 success criterion 1.4.11 (*Non-text Contrast*, level AA) covers this:
 * a focus indicator is visual information required to identify the state of a
 * component, and it owes 3:1.
 *
 * ## What this exists to catch
 *
 * A dark scheme that the focus ring does not follow, which is what the surface
 * shipped with and what nothing noticed:
 *
 * `--focus-color: var(--c-accent)` was declared once, on `:root`. A custom
 * property is substituted at **computed value time on the element that declares
 * it**, so it resolved against the *light* accent and then inherited down as
 * that literal colour. Both dark blocks redefine `--c-accent` on `body` — and
 * neither redefined `--focus-color`, so every focused control on a dark page
 * kept the light accent: `#2563a8` against a `#171c21` page, **2.80:1**.
 *
 * That is a whole class of defect rather than one value, and it is invisible
 * from the stylesheet: the declaration reads exactly like a declaration that
 * works. The `follows the palette` test below pins the mechanism directly, so a
 * future token declared the same way fails on the reason rather than on the
 * symptom.
 *
 * It is also invisible to every other suite. No visual baseline and no
 * documentation screenshot photographs a *focused* control except one, the
 * contrast tests measure resting borders, and a ring that is merely the wrong
 * blue looks entirely deliberate in a picture.
 *
 * ## Why the ring is reached with the keyboard
 *
 * `:focus-visible`, not `:focus` — the stylesheet says so deliberately, to keep
 * a ring off a button somebody clicked. A programmatic `focus()` matches it for
 * a text input and **not** for a button, so a probe that only called `focus()`
 * would report `outline-style: none` for half the controls and could be read as
 * "no ring" rather than "not asked for one".
 */
import type { Locator, Page } from '@playwright/test';
import { expect, test } from '../fixtures';
import { manifest } from '../manifest';
import { contrastRatio } from '../Support/contrast';
import { ProfileEditPage } from '../Support/profileEditPage';

/** WCAG 2.2 success criterion 1.4.11, level AA. */
const MINIMUM_NON_TEXT_CONTRAST = 3;

interface Ring {
    readonly colour: string;
    readonly style: string;
    /** The nearest painted background behind the control, which the offset exposes. */
    readonly behind: string;
}

async function ringOf(control: Locator): Promise<Ring> {
    return control.evaluate((element: HTMLElement): Ring => {
        const isOpaque = (value: string): boolean => {
            const numbers = value.match(/[\d.]+/g);

            return numbers !== null && numbers.length >= 3 && Number.parseFloat(numbers[3] ?? '1') > 0;
        };

        const style = getComputedStyle(element);
        let behind: HTMLElement | null = element.parentElement;
        while (behind !== null && !isOpaque(getComputedStyle(behind).backgroundColor)) {
            behind = behind.parentElement;
        }

        return {
            colour: style.outlineColor,
            style: style.outlineStyle,
            behind: getComputedStyle(behind ?? element.ownerDocument.body).backgroundColor,
        };
    });
}

async function expectAVisibleRing(control: Locator, what: string): Promise<void> {
    const ring = await ringOf(control);

    // Without this the contrast assertion would pass on a control that draws no
    // ring at all: "none" leaves `outline-color` at its inherited value, and a
    // colour nobody paints can have any ratio it likes.
    expect(ring.style, `${what} draws a focus ring at all`).not.toBe('none');
    expect(
        contrastRatio(ring.colour, ring.behind),
        `${what}: the ring (${ring.colour}) sits in a gap showing the page (${ring.behind}), and `
            + 'WCAG 1.4.11 asks for 3:1 between them',
    ).toBeGreaterThanOrEqual(MINIMUM_NON_TEXT_CONTRAST);
}

/** Tabs away and back, so `:focus-visible` applies to a button as well. */
async function focusByKeyboard(page: Page, control: Locator): Promise<void> {
    await control.focus();
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Tab');
}

for (const scheme of ['light', 'dark'] as const) {
    test.describe(`The focus ring on a site pinned to the ${scheme} scheme`, (): void => {
        test('follows the palette rather than the scheme it was declared in', async ({
            page,
            loginAs,
        }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open(manifest.pinnedSchemeEditPagePaths[scheme]);

            // Read both off `body`, which is where this theme resolves its
            // scheme. A token that resolved on `:root` carries the light value
            // down here no matter what the scheme says, and comparing the two is
            // what makes that visible without naming a colour.
            const resolved = await page.evaluate(() => {
                const style = getComputedStyle(document.body);

                return {
                    accent: style.getPropertyValue('--c-accent').trim(),
                    focus: style.getPropertyValue('--focus-color').trim(),
                };
            });

            expect(
                resolved.focus,
                'the focus colour is the accent; if these differ, a token resolved against the light '
                    + 'palette on ":root" and inherited that literal into the dark scheme',
            ).toBe(resolved.accent);
        });

        test('is visible around a focused text input', async ({ page, loginAs }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open(manifest.pinnedSchemeEditPagePaths[scheme]);
            await surface.waitForEnhancement();
            await surface.startRecordEdit('profile');

            const input = surface.control('profile', 'lastname');
            await focusByKeyboard(page, input);
            await expectAVisibleRing(input, 'a focused text input');
        });

        test('is visible around a focused button', async ({ page, loginAs }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open(manifest.pinnedSchemeEditPagePaths[scheme]);
            await surface.waitForEnhancement();

            const button = surface.element.getByRole('button', { name: 'Edit all fields' }).first();
            await focusByKeyboard(page, button);
            await expectAVisibleRing(button, 'a focused button');
        });

        test('is visible around a control the server rejected', async ({
            page,
            loginAs,
        }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open(manifest.pinnedSchemeEditPagePaths[scheme]);
            await surface.waitForEnhancement();
            await surface.startRecordEdit('profile');
            await surface.type('profile', 'shortname', '');
            await surface.submitRecord('profile');

            // This ring is drawn in the danger colour rather than the accent, so
            // it is a second colour with the same duty — and it reaches the
            // element through a different route, an `outline-color` set on the
            // focused-and-invalid rule instead of through the focus token.
            const rejected = surface.control('profile', 'shortname');
            await focusByKeyboard(page, rejected);
            await expectAVisibleRing(rejected, 'a focused control that was rejected');
        });
    });
}
