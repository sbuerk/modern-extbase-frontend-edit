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
        expect(imports['@sbuerk/modern-extbase-frontend-edit/'])
            .toContain('/typo3conf/ext/modern_extbase_frontend_edit/Resources/Public/JavaScript/');

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

    test('the upgraded element does not slot its light DOM children', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // The server rendered view is still in the document - that is what makes
        // it the fallback - but the shadow root renders no <slot>, so none of it
        // is displayed next to the editing surface.
        await expect(surface.element.locator('h3.modern-extbase-frontend-edit-profile-name')).toHaveCount(1);
        await expect(surface.element.locator('h3.modern-extbase-frontend-edit-profile-name')).toBeHidden();
        expect(await page.evaluate((): number =>
            document.querySelector('modern-extbase-frontend-edit-profile')?.shadowRoot
                ?.querySelectorAll('slot').length ?? -1)).toBe(0);
    });
});
