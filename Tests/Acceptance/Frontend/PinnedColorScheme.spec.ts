/**
 * A site that *pins* a colour scheme, and what the editing surface does about it.
 *
 * ## Why this needs its own sites
 *
 * `devSite.colorScheme` is a **site** setting. One site cannot answer it two
 * ways, so the two cases here are two extra sites in the seeded instance —
 * `acme-dark` and `acme-light` — carrying the same edit plugin over the same
 * profile and differing from `acme` in that one value.
 *
 * ## What it is guarding
 *
 * Wiring the surface's ten colour tokens onto the theme's palette changed what a
 * pinned scheme does, and the change was reasoned rather than observed until
 * this file existed. Before it, the extension's dark values sat behind
 * `prefers-color-scheme` and nothing else, so pinning `dark` moved the *page*
 * into the dark palette and left the *surface* in its light one: a white editing
 * surface on a dark page. Mapping colour onto `--c-*` fixes that for free,
 * because those flip on `body[data-color-scheme]`.
 *
 * ## Why the state badge, and why no colour is written down here
 *
 * The badge is styled by `modern-extbase-frontend-edit-profile
 * .frontend-edit-state` — not wrapped in `:where()`, and no configured class
 * touches it — so its colour can only have come from the token layer. That makes
 * it the one element whose painted colour answers the question this file asks.
 *
 * Every expected value is **read from the theme**, never typed in. The whole
 * point of the wiring is that the two agree, and a literal here would be a third
 * copy of the value — the exact defect `-s checkDesignTokenWiring` exists to
 * prevent, reintroduced in a test.
 *
 * The two cases are deliberately opposites, and each is sharp for a different
 * reason:
 *
 * - **Pinned `dark`, browser reporting light.** The extension's own dark block
 *   cannot apply, so an unwired token would paint the extension's *light*
 *   default onto a dark page — which is precisely the defect.
 * - **Pinned `light`, browser emulating dark.** The extension's own dark block
 *   *would* apply, so an unwired token would paint the extension's *dark* value
 *   onto a light page. This is the half that proves the site setting beats the
 *   media query rather than merely agreeing with it.
 */
import { expect, test } from '../fixtures';
import { manifest } from '../manifest';
import { ProfileEditPage } from '../Support/profileEditPage';

/**
 * The colour the surface paints on an element no theme selector and no
 * configured class can reach, and the theme token that is supposed to decide it.
 *
 * Both are read in one `evaluate()` so that they cannot be read from two
 * different renderings of the page.
 */
async function paintedAndIntended(
    surface: ProfileEditPage,
): Promise<{ readonly painted: string; readonly intended: string }> {
    const badge = surface.childRow('address:4').locator('.frontend-edit-state');

    const values = await badge.evaluate((el: HTMLElement): { painted: string; token: string } => ({
        painted: getComputedStyle(el).color,
        // Read off <body>, which is where this theme resolves its scheme.
        token: getComputedStyle(document.body).getPropertyValue('--c-text-muted').trim(),
    }));

    // The token is a hex literal and the painted value is `rgb()`. Resolving it
    // through a throwaway element is what makes the two comparable without this
    // file knowing either notation.
    const intended = await badge.evaluate((el: HTMLElement, value: string): string => {
        const probe = document.createElement('span');
        probe.style.color = value;
        el.ownerDocument.body.append(probe);
        const computed = getComputedStyle(probe).color;
        probe.remove();

        return computed;
    }, values.token);

    return { painted: values.painted, intended };
}

test.describe('A site that pins the dark scheme', (): void => {
    test('renders the page in it', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open(manifest.pinnedSchemeEditPagePaths.dark);

        await expect(page.locator('body')).toHaveAttribute('data-color-scheme', 'dark');
    });

    test('draws the editing surface in it as well', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open(manifest.pinnedSchemeEditPagePaths.dark);
        await surface.waitForEnhancement();

        const { painted, intended } = await paintedAndIntended(surface);

        expect(
            painted,
            'a pinned dark site has to reach the surface, not only the page around it',
        ).toBe(intended);
    });

    test('is a different surface from the one an unpinned site draws', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);

        await surface.open();
        await surface.waitForEnhancement();
        const unpinned = await paintedAndIntended(surface);

        await surface.open(manifest.pinnedSchemeEditPagePaths.dark);
        await surface.waitForEnhancement();
        const pinned = await paintedAndIntended(surface);

        // Without this the two assertions above would both hold on a surface
        // that never changes colour at all, and neither would say so.
        expect(
            pinned.painted,
            'pinning dark has to change what is drawn, or the case is untested',
        ).not.toBe(unpinned.painted);
    });
});

test.describe('A site that pins the light scheme', (): void => {
    // The visitor asks for dark. The site says no, and the site wins.
    test.use({ colorScheme: 'dark' });

    test('keeps the page light against the visitor preference', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open(manifest.pinnedSchemeEditPagePaths.light);

        await expect(page.locator('body')).toHaveAttribute('data-color-scheme', 'light');
    });

    test('keeps the editing surface light with it', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open(manifest.pinnedSchemeEditPagePaths.light);
        await surface.waitForEnhancement();

        const { painted, intended } = await paintedAndIntended(surface);

        expect(
            painted,
            'the site setting has to beat "prefers-color-scheme" for the surface too',
        ).toBe(intended);
    });
});
