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

    test('a child sent to the top is persisted and survives a reload', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Stored order is [2, 3, 1, 4]. Sending 1 to the top has to produce
        // [1, 2, 3, 4] - two positions, which is what separates this from
        // "move up" and is the whole reason the action exists.
        const response = await surface.moveChild('address:1', 'Move to top');

        expect(response.status()).toBe(200);
        await expect
            .poll(async (): Promise<number[]> => surface.renderedChildUids('address'))
            .toEqual([1, 2, 3, 4]);
        expect(childUidsInStoredOrder(ADDRESS_TABLE, OWNED_PROFILE_UID)).toEqual([1, 2, 3, 4]);

        await page.reload();
        await surface.waitForEnhancement();

        expect(await surface.renderedChildUids('address')).toEqual([1, 2, 3, 4]);
    });

    test('a child sent to the bottom is persisted and survives a reload', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Stored order is [2, 3, 1, 4]; sending 2 to the bottom gives [3, 1, 4, 2].
        const response = await surface.moveChild('address:2', 'Move to bottom');

        expect(response.status()).toBe(200);
        await expect
            .poll(async (): Promise<number[]> => surface.renderedChildUids('address'))
            .toEqual([3, 1, 4, 2]);
        expect(childUidsInStoredOrder(ADDRESS_TABLE, OWNED_PROFILE_UID)).toEqual([3, 1, 4, 2]);

        await page.reload();
        await surface.waitForEnhancement();

        expect(await surface.renderedChildUids('address')).toEqual([3, 1, 4, 2]);
    });

    /**
     * No reordering action is drawn where it would do nothing.
     *
     * All four follow one rule now. They briefly did not: the end actions were
     * absent while "Move up" and "Move down" were shown and disabled, which is
     * two statements about the same situation in one toolbar. Absent won,
     * because a disabled control still claims a place in the row and still
     * invites a click that cannot do anything.
     *
     * A record in the middle offers all four, and the first and last offer three
     * - which is also why this asserts the middle case: a test that only checked
     * the ends would pass against a surface that had lost the buttons entirely.
     */
    test('no reordering action is drawn where it would change nothing', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Stored order is [2, 3, 1, 4], so uid 2 is first and uid 4 is last.
        const first = surface.childRow('address:2');
        const last = surface.childRow('address:4');
        const middle = surface.childRow('address:3');

        await expect(first.getByRole('button', { name: 'Move to top', exact: true })).toHaveCount(0);
        await expect(first.getByRole('button', { name: 'Move to bottom', exact: true })).toHaveCount(1);

        await expect(last.getByRole('button', { name: 'Move to bottom', exact: true })).toHaveCount(0);
        await expect(last.getByRole('button', { name: 'Move to top', exact: true })).toHaveCount(1);

        // A record in the middle offers both.
        await expect(middle.getByRole('button', { name: 'Move to top', exact: true })).toHaveCount(1);
        await expect(middle.getByRole('button', { name: 'Move to bottom', exact: true })).toHaveCount(1);

        // The relative moves follow the same rule, which is the whole point of
        // this test: one convention, not two.
        await expect(first.getByRole('button', { name: 'Move up', exact: true })).toHaveCount(0);
        await expect(first.getByRole('button', { name: 'Move down', exact: true })).toHaveCount(1);

        await expect(last.getByRole('button', { name: 'Move down', exact: true })).toHaveCount(0);
        await expect(last.getByRole('button', { name: 'Move up', exact: true })).toHaveCount(1);

        await expect(middle.getByRole('button', { name: 'Move up', exact: true })).toHaveCount(1);
        await expect(middle.getByRole('button', { name: 'Move down', exact: true })).toHaveCount(1);

        // The actions that always apply are on every row, so "absent" never
        // means the toolbar failed to render.
        for (const row of [first, middle, last]) {
            await expect(row.getByRole('button', { name: 'Remove', exact: true })).toHaveCount(1);
        }
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
    /**
     * The dialog is modal, and modal means more than "on top".
     *
     * `showModal()` is used rather than the `open` attribute precisely for the
     * three properties asserted here: the dialog is in the top layer, the
     * background is inert, and the focus is inside it. Rendering `open` gives a
     * box that looks similar and has none of them.
     */
    test('the add form opens as a modal dialog', async ({ page, loginAs }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Closed to begin with: a <dialog> is in the document either way, so
        // this is the assertion that says something.
        await expect(surface.addDialog('email')).toBeHidden();

        await surface.openAddDialog('email');

        expect(await surface.addDialog('email').evaluate(
            (dialog: HTMLDialogElement): boolean => dialog.matches(':modal'),
        )).toBe(true);

        // The focus is inside the dialog, which is what makes it usable from a
        // keyboard at all.
        expect(await surface.addDialog('email').evaluate(
            (dialog: HTMLDialogElement): boolean => dialog.contains(document.activeElement),
        )).toBe(true);
    });

    /**
     * Escape closes it, and this test exists because the answer was not
     * knowable by reading.
     *
     * A field calls `preventDefault()` on Escape before emitting
     * `field-cancel`, and whether that suppresses a dialog's close request is
     * not specified in a way worth relying on. The surface therefore closes the
     * dialog from its own handler, so the outcome is the same whichever path
     * the browser takes - and this asserts the outcome rather than the path.
     */
    test('Escape closes the add dialog and throws the draft away', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await surface.openAddDialog('email');
        await surface.type('email:new', 'email', 'never@example.org');
        await page.keyboard.press('Escape');

        await expect(surface.addDialog('email')).toBeHidden();

        // Reopening starts from a blank form: the discarded draft is gone, not
        // merely hidden behind a closed dialog.
        await surface.openAddDialog('email');
        await expect(surface.control('email:new', 'email')).toHaveValue('');
    });

    test('cancelling the dialog adds nothing and returns the focus to the button', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const before = await surface.renderedChildUids('email');

        await surface.openAddDialog('email');
        await surface.type('email:new', 'email', 'nothing@example.org');
        await surface.cancelAddDialog('email');

        expect(await surface.renderedChildUids('email')).toEqual(before);
        expect(childUidsInStoredOrder(EMAIL_TABLE, OWNED_PROFILE_UID)).toEqual(before);

        // Back where the reader was, rather than at the top of the document.
        expect(await page.evaluate((): string | null =>
            document.activeElement?.getAttribute('data-add-for') ?? null)).toBe('email');
    });

    /**
     * A rejected record keeps the dialog open, which is the whole reason the
     * dialog is not closed optimistically on submit.
     */
    test('a rejected new record keeps the dialog open and shows why', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await surface.openAddDialog('email');
        // Empty address: the rule set refuses it.
        await surface.type('email:new', 'email', '');
        const response = await surface.addChild('email');

        expect(response.status()).toBe(422);
        await expect(surface.addDialog('email')).toBeVisible();
        await expect(surface.fieldErrors('email:new', 'email')).not.toHaveCount(0);
    });

    /**
     * A collection of one offers no reordering at all.
     *
     * The case where "absent rather than disabled" is most visible, and the one
     * the fixture cannot show on its own: every collection it seeds has at
     * least two records, so the only way to reach a single-record collection is
     * to make one. The toolbar drops from six actions to two.
     *
     * It is worth asserting because the two conditions are independent in the
     * component - `index === 0` hides two of the buttons and
     * `index === total - 1` hides the other two - and a record that is both the
     * first and the last satisfies both at once.
     */
    test('a collection of one record offers no reordering action', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Two e-mail addresses are seeded; removing one leaves a collection
        // whose only record is simultaneously the first and the last.
        const response = await surface.removeChild('email:1');
        expect(response.status()).toBe(200);

        await expect
            .poll(async (): Promise<number> => (await surface.renderedChildUids('email')).length)
            .toBe(1);

        const only = surface.childRow('email:2');
        for (const name of ['Move to top', 'Move up', 'Move down', 'Move to bottom']) {
            await expect(
                only.getByRole('button', { name, exact: true }),
                `"${name}" must not be drawn for the only record of a collection`,
            ).toHaveCount(0);
        }

        // What is left is what still means something.
        await expect(only.getByRole('button', { name: 'Hide', exact: true })).toHaveCount(1);
        await expect(only.getByRole('button', { name: 'Remove', exact: true })).toHaveCount(1);
    });

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

        await surface.openAddDialog('email');
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
