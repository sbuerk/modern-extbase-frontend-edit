/**
 * That a site's own styling beats the extension's.
 *
 * This is the guarantee the whole styling round exists for, and it is the one
 * thing none of the other suites can see. `ButtonHierarchy.spec.ts` asserts that
 * a button is marked `primary` and deliberately asserts nothing about colour, so
 * the surface could mark every button correctly and still overrule the design
 * system it was asked to blend into — which is exactly what it did until the
 * appearance rules were moved into `:where()`.
 *
 * The acceptance instance configures the development site package's class names
 * through `$GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit']`, so what
 * is asserted here is the whole chain: configuration → PHP service → DTO → JSON
 * → the class attribute → the theme's stylesheet → the computed colour.
 *
 * The two palettes are deliberately different in the one place it matters. The
 * accent of `test_dev_site` is `#2563a8` and the extension's own is `#0a7bd4`,
 * so "which stylesheet won" is a question a computed value can answer. If the
 * two are ever brought into agreement, this test stops proving anything and has
 * to be given a different discriminator.
 */
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';

/** `test_dev_site`'s `--c-accent`, which the extension's own is not. */
const themeAccent = 'rgb(37, 99, 168)';

/** The extension's `--frontend-edit-color-accent`. */
const extensionAccent = 'rgb(10, 123, 212)';

test.describe('Theme override', (): void => {
    test('a configured class beats the extension on the emphasised button', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();
        await surface.startFieldEdit('profile', 'firstname');

        const apply = surface.field('profile', 'firstname').getByRole('button', { name: 'Apply', exact: true });

        // It really does carry both: its own class and the configured one.
        await expect(apply).toHaveClass(/button/);
        await expect(apply).toHaveClass(/button--primary/);

        const background = await apply.evaluate((el: HTMLElement): string => getComputedStyle(el).backgroundColor);

        expect(background, 'the site theme has to win').toBe(themeAccent);
        expect(background, 'the extension must not overrule the theme').not.toBe(extensionAccent);
    });

    test('a configured class beats the extension on a form control', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();
        await surface.startFieldEdit('profile', 'firstname');

        const control = surface.control('profile', 'firstname');
        await expect(control).toHaveClass(/form-control/);

        /*
         * Polled rather than read once, and that is not flake insurance.
         *
         * The control is focused - `startFieldEdit()` puts the cursor in it -
         * and both stylesheets transition `border-color` over 120ms. A computed
         * style read while a transition is running returns the **interpolated**
         * value, so a single read answered `rgb(39, 100, 168)`: not the
         * extension's colour, not the theme's, and matching nothing anybody
         * wrote. It is the same trap that put three mid-fade screenshots in the
         * manual, in a different disguise.
         *
         * The focused border of a themed control is the theme's accent,
         * `--c-accent` / #2563a8, which the extension's #0a7bd4 is not.
         */
        await expect
            .poll(async (): Promise<string> =>
                control.evaluate((el: HTMLElement): string => getComputedStyle(el).borderTopColor))
            .toBe(themeAccent);
    });

    /**
     * The surface still has to be coherent with no configuration at all, which
     * is the other half of the bargain: the extension's appearance is the
     * weakest thing on the page, not absent.
     */
    test('the extension still draws a surface the theme says nothing about', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // `state` is not configured by the instance, so nothing but the
        // extension's own stylesheet is drawing this badge.
        const badge = surface.childRow('address:4').locator('.frontend-edit-state');
        const border = await badge.evaluate((el: HTMLElement): string => getComputedStyle(el).borderTopStyle);

        expect(border, 'an unconfigured element keeps the extension styling').toBe('solid');
    });
});
