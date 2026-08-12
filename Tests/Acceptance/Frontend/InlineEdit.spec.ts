/**
 * Per-field inline editing, in a real browser, against a running TYPO3.
 *
 * These are the assertions the PHP functional suite and the node unit suite
 * cannot make between them. `ProfileEditPluginTest` proves the server renders
 * the four attributes; `editState.test.ts` proves the state machine behind the
 * drafts. What neither of them touches is the custom element upgrading, the
 * shadow root rendering controls, a real `fetch` carrying a real request token
 * to a real cHash-bearing URL, and the value still being there after a reload.
 *
 * Every spec that asserts a write does it twice, and the pair is deliberate:
 *
 * - the **reloaded page** has to serve the new value, which is the only proof
 *   that the server persisted it rather than the component having redrawn
 *   itself, and
 * - the **raw row** has to carry it, which is the only proof of what was stored
 *   rather than of what a mapper hands back.
 */
import { expect, readColumn, test } from '../fixtures';
import { OWNED_PROFILE_UID, PROFILE_TABLE, ProfileEditPage } from '../Support/profileEditPage';

test.describe('Per-field inline editing', (): void => {
    test('a field saved through the endpoint is served by the server after a reload', async ({
        page,
        loginAs,
        pageErrors,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await expect(surface.displayedValue('profile', 'firstname')).toHaveText('Ada');

        await surface.startFieldEdit('profile', 'firstname');
        await surface.type('profile', 'firstname', 'Adelaide');
        const response = await surface.applyField('profile', 'firstname');

        expect(response.status()).toBe(200);
        await expect(surface.displayedValue('profile', 'firstname')).toHaveText('Adelaide');
        expect(readColumn(PROFILE_TABLE, OWNED_PROFILE_UID, 'firstname')).toBe('Adelaide');

        await page.reload();
        await surface.waitForEnhancement();

        // The attribute, not the rendered surface: this is the document the
        // server put into the markup, so asserting it is asserting what the
        // server serves rather than what the component remembers.
        await expect(surface.element).toHaveAttribute('data-profile', /"firstname":"Adelaide"/);
        await expect(surface.displayedValue('profile', 'firstname')).toHaveText('Adelaide');

        expect(pageErrors).toEqual([]);
    });

    test('cancel reverts to the last server known value, not to the one the page was loaded with', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // First a successful save, because that is what makes the two candidate
        // values differ at all. Without it, "revert to the page load value" and
        // "revert to the server value" are the same string and the assertion
        // below would hold for the wrong implementation.
        await surface.startFieldEdit('profile', 'firstname');
        await surface.type('profile', 'firstname', 'Adelaide');
        await surface.applyField('profile', 'firstname');
        await expect(surface.displayedValue('profile', 'firstname')).toHaveText('Adelaide');

        await surface.startFieldEdit('profile', 'firstname');
        await surface.type('profile', 'firstname', 'Augusta');
        await surface.cancelField('profile', 'firstname');

        await expect(surface.displayedValue('profile', 'firstname')).toHaveText('Adelaide');
        expect(readColumn(PROFILE_TABLE, OWNED_PROFILE_UID, 'firstname')).toBe('Adelaide');
    });

    test('a validation failure is shown at the field and keeps what was typed', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await surface.startFieldEdit('profile', 'shortname');
        await surface.type('profile', 'shortname', '');
        const response = await surface.applyField('profile', 'shortname');

        expect(response.status()).toBe(422);
        await expect(surface.fieldErrors('profile', 'shortname')).toHaveText(['Enter a short name.']);

        // The session stays open with the draft in it. A failed save that
        // discards what the user typed is worse than one that reports nothing.
        await expect(surface.control('profile', 'shortname')).toHaveValue('');
        expect(readColumn(PROFILE_TABLE, OWNED_PROFILE_UID, 'shortname')).toBe('ada');

        // And the server did not change its mind either.
        await page.reload();
        await surface.waitForEnhancement();
        await expect(surface.displayedValue('profile', 'shortname')).toHaveText('ada');
    });

    test('the focus moves into the control of the field that was switched into edit', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await surface.startFieldEdit('profile', 'lastname');

        await expect
            .poll(async (): Promise<string | null> => (await surface.focusedField()).field)
            .toBe('profile|lastname');
        expect((await surface.focusedField()).control).toBe('input');
    });

    test('Escape cancels the field the focus is in', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await surface.startFieldEdit('profile', 'lastname');
        await surface.type('profile', 'lastname', 'Byron');
        await surface.control('profile', 'lastname').press('Escape');

        await expect(surface.control('profile', 'lastname')).toHaveCount(0);
        await expect(surface.displayedValue('profile', 'lastname')).toHaveText('Lovelace');
        expect(readColumn(PROFILE_TABLE, OWNED_PROFILE_UID, 'lastname')).toBe('Lovelace');
    });

    test('Enter applies the field the focus is in', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await surface.startFieldEdit('profile', 'lastname');
        await surface.type('profile', 'lastname', 'Byron');

        const pending = page.waitForResponse((response): boolean =>
            response.url().includes('%5Baction%5D=saveField'));
        await surface.control('profile', 'lastname').press('Enter');
        expect((await pending).status()).toBe(200);

        await expect(surface.displayedValue('profile', 'lastname')).toHaveText('Byron');
        expect(readColumn(PROFILE_TABLE, OWNED_PROFILE_UID, 'lastname')).toBe('Byron');
    });
});
