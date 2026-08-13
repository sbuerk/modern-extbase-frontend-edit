/**
 * Adding, removing, reordering and unhiding a child record.
 *
 * These are the operations the design argument of the edit plugin rests on:
 * they produce records the server never rendered markup for, which is why the
 * component renders its collections from state rather than enhancing the
 * server's `<li>` elements in place. That decision is only defensible if the
 * operations actually work in a browser, and it is only observable in one.
 *
 * A reorder sends the whole resulting order rather than a delta, and the
 * endpoint replaces the collection with it. That makes "the order survived a
 * reload" the assertion that matters: a component that renders a moved item
 * without sending the order looks identical until the page is reloaded.
 */
import { childUidsInStoredOrder, expect, readColumn, test } from '../fixtures';
import type { Target } from '../Support/profileEditPage';
import {
    ADDRESS_TABLE,
    EMAIL_TABLE,
    OWNED_ADDRESS_UIDS,
    OWNED_EMAIL_UIDS,
    OWNED_PROFILE_UID,
    ProfileEditPage,
} from '../Support/profileEditPage';

/**
 * The endpoint actions with no control of their own - a reorder and a
 * visibility toggle are a click on a button, not an editing session - are
 * awaited by URL here rather than through a page object method, so that the
 * action a spec waits for is stated in the spec that waits for it.
 */
const endpointUrl = (action: string): string => `%5Baction%5D=${action}`;

test.describe('Child collections', (): void => {
    test('the surface renders the stored order, the owner\'s hidden record included', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        expect(await surface.renderedChildUids('address')).toEqual(OWNED_ADDRESS_UIDS);
        expect(await surface.renderedChildUids('email')).toEqual(OWNED_EMAIL_UIDS);

        // Address 4 is hidden in the fixture, and the editing surface is the one
        // view that has to show it - it is the record the owner has to be able
        // to find again in order to publish it.
        await expect(surface.childRow('address:4').locator('.frontend-edit-state')).toHaveText('Hidden');
    });

    test('a reorder is persisted and survives a reload', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Stored order is [2, 3, 1, 4]; moving 3 up has to produce [3, 2, 1, 4].
        const response = await surface.moveChild('address:3', 'Move up');

        expect(response.status()).toBe(200);
        await expect
            .poll(async (): Promise<number[]> => surface.renderedChildUids('address'))
            .toEqual([3, 2, 1, 4]);
        expect(childUidsInStoredOrder(ADDRESS_TABLE, OWNED_PROFILE_UID)).toEqual([3, 2, 1, 4]);

        await page.reload();
        await surface.waitForEnhancement();

        expect(await surface.renderedChildUids('address')).toEqual([3, 2, 1, 4]);
    });

    test('a removed child is gone after a reload', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const response = await surface.removeChild('email:1');

        expect(response.status()).toBe(200);
        await expect
            .poll(async (): Promise<number[]> => surface.renderedChildUids('email'))
            .toEqual([2]);
        expect(childUidsInStoredOrder(EMAIL_TABLE, OWNED_PROFILE_UID)).toEqual([2]);

        await page.reload();
        await surface.waitForEnhancement();

        expect(await surface.renderedChildUids('email')).toEqual([2]);
        await expect(surface.element).not.toHaveAttribute('data-profile', /second@example\.org/);
    });

    /**
     * The add form is the one record that is typed into without ever being
     * opened - its controls are always rendered, so nothing calls
     * `beginFieldEdit()` and its session holds drafts and nothing else. That
     * makes the reload the assertion that matters here for a second reason: a
     * component that discards those drafts submits the values a new record
     * starts from while the controls still show what was typed, so the page
     * looks right and the row is wrong.
     */
    test('a child added through the form is stored with what was typed', async ({
        page,
        loginAs,
        pageErrors,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        expect(await surface.renderedChildUids('email')).toEqual(OWNED_EMAIL_UIDS);

        await surface.choose('email:new', 'type', 'business');
        await surface.type('email:new', 'email', 'third@example.org');
        const response = await surface.addChild('email');

        expect(response.status()).toBe(200);

        // The uid is read rather than assumed: it is the server's, and the only
        // thing the spec knows about it is that the new record sorts last.
        await expect
            .poll(async (): Promise<number> => (await surface.renderedChildUids('email')).length)
            .toBe(OWNED_EMAIL_UIDS.length + 1);
        const stored = childUidsInStoredOrder(EMAIL_TABLE, OWNED_PROFILE_UID);
        const added = stored.at(-1) ?? 0;
        const addedTarget = `email:${added}` as Target;

        expect(stored).toEqual([...OWNED_EMAIL_UIDS, added]);
        expect(readColumn(EMAIL_TABLE, added, 'email')).toBe('third@example.org');
        expect(readColumn(EMAIL_TABLE, added, 'type')).toBe('business');

        // The form starts over rather than keeping what created the record -
        // both controls, so a second child is not added from the first one's
        // leftovers.
        await expect(surface.control('email:new', 'email')).toHaveValue('');
        await expect(surface.control('email:new', 'type')).toHaveValue('others');

        await page.reload();
        await surface.waitForEnhancement();

        expect(await surface.renderedChildUids('email')).toEqual([...OWNED_EMAIL_UIDS, added]);
        await expect(surface.element).toHaveAttribute('data-profile', /third@example\.org/);
        await expect(surface.displayedValue(addedTarget, 'email')).toHaveText('third@example.org');

        expect(pageErrors).toEqual([]);
    });

    test('unhiding a child is persisted and survives a reload', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const pending = page.waitForResponse((response): boolean =>
            response.url().includes(endpointUrl('setChildVisibility')));
        await surface.childRow('address:4').getByRole('button', { name: 'Show', exact: true }).click();

        expect((await pending).status()).toBe(200);
        await expect(surface.childRow('address:4').locator('.frontend-edit-state')).toHaveCount(0);
        expect(readColumn(ADDRESS_TABLE, 4, 'hidden')).toBe(0);

        await page.reload();
        await surface.waitForEnhancement();

        await expect(surface.childRow('address:4').locator('.frontend-edit-state')).toHaveCount(0);
        await expect(surface.childRow('address:4').getByRole('button', { name: 'Hide', exact: true }))
            .toBeVisible();
    });
});
