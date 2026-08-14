/**
 * Whether a control's edge is visible against what it is drawn on, in a real
 * page, in both colour schemes.
 *
 * ## Two layers, and this file has to measure both
 *
 * On a themed page the resting edge of a control is decided **twice**, and the
 * two answers are independent:
 *
 * 1. The **token**, `--frontend-edit-color-border-control`, mapped here onto the
 *    theme's palette by `packages/dev-site/…/_plugin.css`.
 * 2. The **configured class**. This instance sets `classes.button` to the site
 *    package's own `button` and `classes.control` to `form-control`, so
 *    `_button.css` and `_form.css` draw those two elements and their border
 *    wins over the token — a rule with a class beats the surface's `:where()`
 *    default. That is the seam working as designed, and it means the painted
 *    edge of a button on this instance is the *theme's* edge.
 *
 * Measuring only the painted edge would therefore pass while the token was
 * mapped to something invisible, because on this page nothing draws with it —
 * that is not a hypothetical, it is what the first version of this file did.
 * Measuring only the token would pass while the theme's own controls were
 * illegible, which is the defect that started this: `--c-border-strong` was
 * `--c-neutral-40` and measured 2.18:1 against the surface, so every `.button`
 * and every `.form-control` on the site had an edge that was decoration.
 *
 * So: the token tests pin the mapping, the painted tests pin what a visitor
 * gets, and neither subsumes the other.
 *
 * The extension's own defaults — what a project that themes nothing receives —
 * are not visible from here at all, because every page this suite drives carries
 * the site package. `Tests/Unit/Styling/ControlBorderContrastTest` measures those
 * from the shipped stylesheet.
 *
 * ## Why the pinned sites rather than `colorScheme` emulation
 *
 * Emulating `prefers-color-scheme` reaches the *extension's* dark block rather
 * than the *theme's*. The theme resolves its scheme from
 * `body[data-color-scheme]`, which a site setting decides — so the two sites
 * `acme-dark` and `acme-light` that `PinnedColorScheme.spec.ts` introduced are
 * what puts a themed page into a known scheme.
 *
 * ## Why no expected colour is written down
 *
 * Every value is read from the page and the threshold is the criterion's, so
 * this file states no palette of its own. Re-theming the site is free; making
 * one of its controls invisible is not.
 */
import type { Locator } from '@playwright/test';
import { expect, test } from '../fixtures';
import { manifest } from '../manifest';
import { contrastRatio } from '../Support/contrast';
import { ProfileEditPage } from '../Support/profileEditPage';

/** WCAG 2.2 success criterion 1.4.11, level AA. */
const MINIMUM_NON_TEXT_CONTRAST = 3;

interface ControlColours {
    /** The resting border, which is the information the criterion is about. */
    readonly border: string;
    /** The control's own fill. */
    readonly fill: string;
    /** The nearest painted background behind the control. */
    readonly behind: string;
}

/**
 * The three colours that decide whether a control has a visible edge.
 *
 * `behind` is resolved by walking ancestors until one paints an opaque
 * background, which is what a viewer's eye does: the control's parent is usually
 * transparent, so comparing against it would compare the border with nothing and
 * report a ratio of 1 for a perfectly legible control.
 */
async function coloursOf(control: Locator): Promise<ControlColours> {
    return control.evaluate((element: HTMLElement): ControlColours => {
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
            border: style.borderTopColor,
            fill: style.backgroundColor,
            behind: getComputedStyle(behind ?? element.ownerDocument.body).backgroundColor,
        };
    });
}

/**
 * What a design token resolves to on the surface, as a comparable colour.
 *
 * The computed value of a custom property already has its `var()` chain
 * substituted, so this reads the end of the mapping rather than the mapping. It
 * is then pushed through a throwaway element because the theme writes hex and
 * the painted values are `rgb()`, and this file should know neither notation.
 */
async function resolvedToken(surface: Locator, token: string): Promise<string> {
    return surface.evaluate((element: HTMLElement, name: string): string => {
        const probe = document.createElement('span');
        probe.style.color = getComputedStyle(element).getPropertyValue(name).trim();
        element.ownerDocument.body.append(probe);
        const computed = getComputedStyle(probe).color;
        probe.remove();

        return computed;
    }, token);
}

function expectMeetsMinimum(border: string, against: string, what: string): void {
    expect(
        contrastRatio(border, against),
        `${what}: ${border} against ${against} is what says the control is there, and WCAG 1.4.11 ` +
            'asks for 3:1',
    ).toBeGreaterThanOrEqual(MINIMUM_NON_TEXT_CONTRAST);
}

async function expectAVisibleEdge(control: Locator, what: string): Promise<void> {
    const colours = await coloursOf(control);

    // Both sides matter and for different reasons: against the fill because that
    // is the boundary of the component, against the page because a control whose
    // fill matches the page is delimited by the border alone.
    expectMeetsMinimum(colours.border, colours.fill, `${what}, against the fill it encloses`);
    expectMeetsMinimum(colours.border, colours.behind, `${what}, against the page behind it`);
}

for (const scheme of ['light', 'dark'] as const) {
    test.describe(`A control on a site pinned to the ${scheme} scheme`, (): void => {
        test('resolves the control border token to a value that stays visible', async ({
            page,
            loginAs,
        }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open(manifest.pinnedSchemeEditPagePaths[scheme]);
            await surface.waitForEnhancement();

            const border = await resolvedToken(surface.element, '--frontend-edit-color-border-control');

            for (const fill of ['--frontend-edit-color-surface', '--frontend-edit-color-surface-sunken']) {
                expectMeetsMinimum(
                    border,
                    await resolvedToken(surface.element, fill),
                    `the control border token against ${fill}`,
                );
            }
        });

        test('keeps the control border token apart from the decorative one', async ({
            page,
            loginAs,
        }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open(manifest.pinnedSchemeEditPagePaths[scheme]);
            await surface.waitForEnhancement();

            const control = await resolvedToken(surface.element, '--frontend-edit-color-border-control');
            const decoration = await resolvedToken(surface.element, '--frontend-edit-color-border');

            // Collapsing the two back onto one token is the regression that
            // would undo the split, and it would leave every assertion above
            // passing if the survivor happened to be the strong one — while
            // turning every separator into a control-strength line.
            expect(
                decoration,
                'a separator and a control edge are different roles and must not share a value',
            ).not.toBe(control);
        });

        test('paints a resting button edge that meets the non-text contrast minimum', async ({
            page,
            loginAs,
        }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open(manifest.pinnedSchemeEditPagePaths[scheme]);
            await surface.waitForEnhancement();

            // The unemphasised button. `Apply`, `Add` and `Remove` carry a
            // variant that gives them a fill or a colour of their own, so they
            // are not the case at risk — the default one is.
            await expectAVisibleEdge(
                surface.element.getByRole('button', { name: 'Edit all fields' }).first(),
                'the default button',
            );
        });

        test('paints a resting input edge that meets the non-text contrast minimum', async ({
            page,
            loginAs,
        }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open(manifest.pinnedSchemeEditPagePaths[scheme]);
            await surface.waitForEnhancement();
            await surface.startRecordEdit('profile');

            // Not focused: the focus ring is a second, stronger indicator, and
            // measuring a focused control would pass a surface whose controls
            // are invisible until reached.
            await expectAVisibleEdge(surface.control('profile', 'lastname'), 'a text input');
        });
    });
}
