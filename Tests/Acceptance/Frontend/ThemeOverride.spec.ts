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
 * → the class attribute → the theme's stylesheet → the computed style.
 *
 * ## The discriminator used to be colour, and cannot be any more
 *
 * This file was built on the two palettes disagreeing: `test_dev_site`'s accent
 * was `#2563a8`, the extension's own `#0a7bd4`, and "which stylesheet won" was
 * therefore a question a computed colour could answer. The docblock said in as
 * many words that bringing them into agreement would stop these tests proving
 * anything.
 *
 * They were then brought into agreement on purpose: the site package maps all
 * ten of the surface's colour tokens onto its own palette, so that there is one
 * palette on the page rather than two that nearly match. The accent a themed
 * button draws is now the accent an *unthemed* one would draw, and a colour
 * assertion here would pass whatever happened to the `:where()` wrapping.
 *
 * So the discriminator is **type**, which no token can carry across. The theme's
 * `.button` sets `font-size: var(--text-sm)` and `font-weight: var(--weight-button)`;
 * the extension's own button rule sets `font: inherit` and no weight at all, and
 * neither value is reachable through the token layer. A surface whose buttons
 * are 14px and semi-bold is a surface the theme is styling.
 *
 * The same rule applies to whatever replaces this if the theme's type scale ever
 * moves: the discriminator has to be a property the theme decides **only**
 * through a class, never through a token the site package maps.
 */
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';

/** `test_dev_site`'s `--text-sm` and `--weight-button`, which no token carries. */
const themeButtonFontSize = '14px';
const themeButtonFontWeight = '500';

/** What the extension's own `font: inherit` resolves to on this page. */
const unthemedButtonFontSize = '16px';

/**
 * `test_dev_site`'s `--c-accent`. Still a valid answer for the one control
 * property the extension does not style at all — see the control test.
 */
const themeAccent = 'rgb(37, 99, 168)';

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

        const type = await apply.evaluate((el: HTMLElement): { size: string; weight: string } => {
            const style = getComputedStyle(el);
            return { size: style.fontSize, weight: style.fontWeight };
        });

        expect(type.size, 'the site theme has to win').toBe(themeButtonFontSize);
        expect(type.weight, 'the site theme has to win').toBe(themeButtonFontWeight);
        expect(type.size, 'the extension must not overrule the theme').not.toBe(unthemedButtonFontSize);
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
         * Colour still discriminates here, where it no longer does on the
         * button, and the reason is worth stating: the extension has **no**
         * focused border rule at all. It draws a focus ring as an outline and
         * leaves the border alone, so an unthemed control keeps
         * `--frontend-edit-color-border` while a themed one turns accent
         * coloured. That difference comes from a rule only the theme has, not
         * from a value the token layer carries, which is what makes it a valid
         * discriminator after the palettes were unified.
         */
        await expect
            .poll(async (): Promise<string> =>
                control.evaluate((el: HTMLElement): string => getComputedStyle(el).borderTopColor))
            .toBe(themeAccent);
    });

    /**
     * A site can override a design token, which is what the documentation has
     * promised all along and what stopped being true without anyone noticing.
     *
     * Under a shadow root it worked for free: a declaration in the outer tree
     * beats a `:host` default whatever the source order. Moving into the light
     * DOM turned both into ordinary rules on the same element with the same
     * specificity, so source order decided — and the extension's stylesheet is
     * emitted by the plugin, *after* the site's. The site's override lost every
     * time, and nothing exercised the mechanism, so nothing said so.
     *
     * The surface's token defaults are declared at zero specificity now. This
     * test is what stops that regressing a second time: it fails if the
     * `:where()` is removed from the token block, and it fails if the site
     * package stops declaring them.
     *
     * It works by choosing a token whose value the theme sets **differently**
     * from the extension's own default, and there is now exactly one left. Every
     * other token the site package maps is mapped to the value the extension
     * already had, or to a colour from a palette that is meant to replace the
     * extension's — either way the two agree, by construction rather than by
     * luck, and a token that agrees cannot answer "whose declaration won".
     *
     * The easing curve is the exception on purpose. The extension defaults to
     * `ease`, the theme declares a curve of its own in `--transition-easing`,
     * and the surface follows the theme — so this reads as a real override and
     * not as a value that would have been right anyway.
     *
     * The token is deliberately one with no pixels attached. The measure was
     * tried first and moved six baselines — a visual change made for a test's
     * convenience, which is the wrong trade. An easing curve is readable in a
     * computed style and invisible in every screenshot, because both image
     * suites disable animations.
     */
    test('a design token declared by the site wins over the extension default', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const easing = await surface.element.evaluate((el: HTMLElement): string =>
            getComputedStyle(el).getPropertyValue('--frontend-edit-transition-easing').trim());

        expect(easing, 'the site package sets its own curve; the extension defaults to "ease"')
            .toBe('cubic-bezier(0.2, 0, 0.2, 1)');
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

    /**
     * And that it draws it in the theme's colours anyway.
     *
     * This is the half of the wiring that has no other witness. A class reaches
     * the elements an installation configures — buttons, controls, labels — and
     * every assertion above is about one of those. The badge, the marker down
     * the side of a child record and the dashed edge of the add form are
     * configured by nothing, so the only thing that can carry a theme's palette
     * to them is the token layer.
     *
     * It is asserted here rather than left to the image suites because they
     * cannot see it: the ten visual baselines are crops of components, and every
     * one of them passed unchanged when the ten colour tokens were wired — the
     * shifts are small per pixel and the accent is not inside any crop. A
     * mapping deleted from the site package would show up in nothing at all.
     *
     * `--c-border` rather than a literal: the point is that the two agree, and
     * hard coding the theme's current grey here would be a third copy of the
     * value this whole change exists to stop making.
     */
    test('an unconfigured element is drawn in the theme colours', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const badge = surface.childRow('address:4').locator('.frontend-edit-state');
        const drawn = await badge.evaluate((el: HTMLElement): { border: string; theme: string } => ({
            border: getComputedStyle(el).borderTopColor,
            // Read off <body>, which is where this theme resolves its scheme.
            theme: getComputedStyle(document.body).getPropertyValue('--c-border').trim(),
        }));

        const asRgb = await page.evaluate((value: string): string => {
            const probe = document.createElement('span');
            probe.style.color = value;
            document.body.append(probe);
            const computed = getComputedStyle(probe).color;
            probe.remove();
            return computed;
        }, drawn.theme);

        expect(drawn.border, 'the theme palette has to reach an element no class touches').toBe(asRgb);
    });
});
