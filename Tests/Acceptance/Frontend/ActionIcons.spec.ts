/**
 * That the icons are decoration, and that hiding a label does not remove it.
 *
 * The record toolbars — move, hide, remove, once per child — drop their visible
 * text and keep only a glyph. That is a presentation decision, and it is one
 * step away from an accessibility defect: the obvious way to build an icon-only
 * button is to put its name in `aria-label` and render nothing else, which
 * reads correctly to a screen reader and leaves the button with no text at all.
 *
 * This extension does it the other way round — a visually hidden `<span>` — for
 * three reasons, and each has a test below:
 *
 * 1. The accessible name survives, so `getByRole('button', { name })` keeps
 *    working. That is how `profileEditPage.ts` addresses every button.
 * 2. `textContent` survives, which `ButtonHierarchy.spec.ts` reads.
 * 3. The label is still there when the stylesheet is not. A visually hidden span
 *    degrades into visible text; an `aria-label` degrades into nothing.
 *
 * What is *not* asserted here is what an icon looks like. The glyph is
 * `aria-hidden`, no spec reads its path data, and the drawing may change without
 * this file noticing — which is the correct division: this pins the contract,
 * the six generated screenshots show the drawing.
 */
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';

/**
 * The record toolbar of the first owned address, which is the only place the
 * surface hides a label.
 */
const toolbarLabels = ['Move up', 'Move down', 'Hide', 'Remove'] as const;

test.describe('Action icons', (): void => {
    test('a toolbar button keeps the name its label gives it', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const row = surface.childRow('address:2');
        for (const name of toolbarLabels) {
            const button = row.getByRole('button', { name, exact: true });
            await expect(button, `${name} is addressable by its accessible name`).toHaveCount(1);
            await expect(button, `${name} is an icon-only button`).toHaveAttribute('data-icon-only', '');
            // The name has to come from real text, not from an attribute: an
            // aria-label would satisfy the assertion above and leave the button
            // empty for everything that reads textContent.
            await expect(button).toHaveText(name);
        }
    });

    test('the label of a toolbar button is hidden, and only visually', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Hidden: clipped to a pixel, so it occupies no width in the toolbar.
        const hidden = surface.childRow('address:2')
            .getByRole('button', { name: 'Move up', exact: true })
            .locator('.frontend-edit-button-label');
        const hiddenBox = await hidden.boundingBox();
        expect(hiddenBox?.width ?? 0, 'a hidden label takes no room').toBeLessThanOrEqual(1);

        // Shown: the same element, in a button that is not part of a toolbar.
        const shown = surface.field('profile', 'firstname')
            .getByRole('button', { name: 'Edit', exact: true })
            .locator('.frontend-edit-button-label');
        const shownBox = await shown.boundingBox();
        expect(shownBox?.width ?? 0, 'a visible label is laid out').toBeGreaterThan(1);
    });

    test('every icon is hidden from assistive technology', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const glyphs = await surface.element.locator('button .frontend-edit-icon').evaluateAll(
            (icons: Element[]): { hidden: string | null; focusable: string | null }[] =>
                icons.map((svg: Element): { hidden: string | null; focusable: string | null } => ({
                    hidden: svg.getAttribute('aria-hidden'),
                    focusable: svg.getAttribute('focusable'),
                })),
        );

        // Every button draws one, so an empty result would mean the icons never
        // rendered rather than that they are all correct.
        expect(glyphs.length).toBeGreaterThan(0);
        expect(glyphs.filter(({ hidden }): boolean => hidden !== 'true')).toEqual([]);
        // "focusable" is not needed by any browser this extension targets, and
        // is asserted because it is cheap insurance rather than because a bug
        // was observed: an SVG that a browser treats as focusable puts a stop in
        // the tab order between two buttons, and a keyboard user would find that
        // before a test did.
        expect(glyphs.filter(({ focusable }): boolean => focusable !== 'false')).toEqual([]);
    });
});
