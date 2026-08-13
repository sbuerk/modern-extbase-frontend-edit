/**
 * The two promises the whole design rests on, and which nothing else can check.
 *
 * ## Degradation
 *
 * "The server rendered profile is the no-JavaScript view" is the sentence the
 * edit plugin's documentation opens its degradation section with, and until this
 * spec existed it was an intention. A browser with JavaScript switched off is
 * the only way to observe it: the element is then an unknown tag with children,
 * and those children are the profile.
 *
 * ## The import map
 *
 * `Configuration/JavaScriptModules.php` declares a dependency on `core` and
 * nothing else, and that single line is what makes the bare specifier `lit`
 * resolve on a *frontend* page - core's own module map declares it. Nothing
 * bundles lit, deliberately, because two lit runtimes on one page break custom
 * element registration rather than merely wasting bytes.
 *
 * The consequence is that a lit major version bump in TYPO3 core would reach
 * this extension as a broken page, and the PHP suite cannot see it: it asserts
 * that an `<script type="importmap">` was emitted, not that a browser can
 * resolve anything out of it. So this spec resolves it, in the page, for real.
 */
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';

test.describe('Progressive enhancement', (): void => {
    test.describe('without JavaScript', (): void => {
        test.use({ javaScriptEnabled: false });

        test('the profile stays readable and the element never upgrades', async ({
            page,
            loginAs,
        }): Promise<void> => {
            await loginAs('owner');
            const surface = new ProfileEditPage(page);
            await surface.open();

            // The element is in the markup either way. What tells the two states
            // apart is whether its children are the page or the shadow root is.
            await expect(surface.element).toHaveCount(1);
            await expect(surface.field('profile', 'firstname')).toHaveCount(0);

            await expect(surface.element.locator('h3.modern-extbase-frontend-edit-profile-name'))
                .toHaveText('Ada Lovelace');
            await expect(
                surface.element.locator('.modern-extbase-frontend-edit-profile-addresses li'),
            ).toHaveCount(4);
            await expect(
                surface.element.locator('.modern-extbase-frontend-edit-profile-emails li'),
            ).toHaveCount(2);

            // The record the owner hid is in the readable view too, and it is
            // marked - an unmarked hidden address would read as published.
            await expect(
                surface.element.locator('.modern-extbase-frontend-edit-profile-addresses .modern-extbase-frontend-edit-profile-state'),
            ).toHaveText(['Hidden']);

            // The stylesheet is gated on a class the module sets, so nothing is
            // styled either. Asserting the gate rather than a computed style,
            // because the gate is the mechanism.
            await expect(page.locator('html.frontend-edit-loaded')).toHaveCount(0);
        });

        test('an anonymous visitor gets the login sentence and no element at all', async ({
            page,
        }): Promise<void> => {
            const surface = new ProfileEditPage(page);
            await surface.open();

            await expect(surface.element).toHaveCount(0);
            await expect(page.locator('.modern-extbase-frontend-edit-profile-edit-anonymous'))
                .toContainText('You are not logged in.');
        });
    });

    test('lit resolves from the frontend import map', async ({ page, loginAs, pageErrors }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // The declaration: core's module map, reachable from a frontend page
        // because JavaScriptModules.php depends on "core".
        const imports = await page.locator('script[type="importmap"]').evaluate(
            (element: Element): Record<string, string> =>
                (JSON.parse(element.textContent ?? '{}') as { imports?: Record<string, string> }).imports ?? {},
        );
        expect(imports.lit).toContain('/typo3/sysext/core/Resources/Public/JavaScript/Contrib/lit/');
        expect(imports['@sbuerk/modern-extbase-frontend-edit/frontend/'])
            .toContain('/typo3conf/ext/modern_extbase_frontend_edit/Resources/Public/JavaScript/frontend/');

        // The build is unbundled, so every module of this extension is enumerated
        // into the map as an entry of its own, carrying the cache busting key
        // that a relative import between two of them would not get. A regression
        // to a single bundled file would leave the prefix above intact and only
        // these entries would disappear, which is why they are asserted rather
        // than inferred from the prefix.
        const own = Object.keys(imports)
            .filter((specifier: string): boolean => specifier.startsWith('@sbuerk/modern-extbase-frontend-edit/frontend/')
                && specifier.endsWith('.js'));
        expect(own.length).toBeGreaterThan(1);
        expect(own).toContain('@sbuerk/modern-extbase-frontend-edit/frontend/model/editState.js');
        for (const specifier of own) {
            expect(imports[specifier]).toContain('?bust=');
        }

        // The resolution: a bare specifier, imported in the page's own realm,
        // yielding the class the components extend. This is the assertion a lit
        // major bump in core has to trip over.
        const litExports = await page.evaluate(async (): Promise<Record<string, string>> => {
            const module = await import('lit');

            return {
                LitElement: typeof (module as { LitElement?: unknown }).LitElement,
                html: typeof (module as { html?: unknown }).html,
                css: typeof (module as { css?: unknown }).css,
            };
        });
        expect(litExports).toEqual({ LitElement: 'function', html: 'function', css: 'function' });

        // And the module of this extension resolved out of the same map: both
        // custom elements are registered, and the surface is a shadow root.
        const registered = await page.evaluate((): boolean[] => [
            customElements.get('modern-extbase-frontend-edit-profile') !== undefined,
            customElements.get('modern-extbase-frontend-edit-field') !== undefined,
        ]);
        expect(registered).toEqual([true, true]);

        expect(pageErrors).toEqual([]);
    });

    /**
     * The replacement for a test that asserted the opposite mechanism.
     *
     * While the components rendered into a shadow root this read "the upgraded
     * element does not slot its light DOM children": the server rendered view
     * stayed in the document and was simply never rendered, because the shadow
     * root contained no `<slot>`. Hiding it cost no code.
     *
     * In the light DOM there is no such thing. `lit-html` inserts its parts into
     * the container and leaves whatever is already there, so the fallback would
     * be displayed *beside* the editing surface - the profile twice, once
     * static and once live, with the static copy going stale on the first save.
     * The element therefore removes it explicitly when it takes over.
     *
     * The two assertions below are the ones that matter about that: it is gone,
     * and the surface that replaced it is real.
     */
    test('the upgraded element replaces the server rendered view', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Not merely hidden: removed. A hidden copy of the whole profile would
        // still be in the document, and would still be showing the values the
        // page was loaded with after the first save.
        await expect(surface.element.locator('h3.modern-extbase-frontend-edit-profile-name')).toHaveCount(0);

        // And nothing renders into a shadow root any more, which is the change
        // that made the removal necessary in the first place.
        expect(await page.evaluate((): boolean =>
            document.querySelector('modern-extbase-frontend-edit-profile')?.shadowRoot === null)).toBe(true);

        // The surface itself is there, in the light DOM, where the page can see
        // it.
        await expect(surface.field('profile', 'firstname')).toHaveCount(1);
    });
});
