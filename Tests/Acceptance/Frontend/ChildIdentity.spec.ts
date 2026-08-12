/**
 * That a child record is named by what it is, and not by where it stands.
 *
 * A profile's addresses render as a list of blocks that look alike, and the
 * heading is what tells them apart — for a reader, and for the toolbar beside
 * it, whose `Move up` and `Remove` otherwise name no record at all.
 *
 * The design decision under test is the second one. "Address 1, Address 2" is
 * the obvious labelling and it is wrong here, because **this surface reorders
 * these records**: a number names a position, so pressing `Move up` would rename
 * every row below the one that moved — at exactly the moment a reader most needs
 * to keep track of the thing they just moved. The last test is that claim, and
 * it is the reason this file is not simply folded into `ChildCollections`.
 *
 * The fixture addresses are uid 2 "work", 3 "other", 1 "home" and 4 "home",
 * hidden, in that stored order; the e-mail addresses are uid 2 "business" and
 * uid 1 "private". Both sets are in
 * `Tests/Functional/Fixtures/Database/ProfilePlugins.csv`, which is where these
 * expectations were read from rather than recalled.
 */
import { expect, test } from '../fixtures';
import { OWNED_ADDRESS_UIDS, ProfileEditPage } from '../Support/profileEditPage';

test.describe('Child identity', (): void => {
    test('each child is headed by its own type and its own first line', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // The type is translated through the same label map the fields use, so
        // a heading reading "work" rather than "Work" would mean the component
        // put a stored value on screen.
        await expect(surface.childTitle('address:2')).toHaveText('Work · Difference Engine Road 1');
        await expect(surface.childTitle('email:2')).toHaveText('Business · first@example.org');

        // One heading per rendered child, and no more: the add form is not a
        // record and has nothing to be identified by.
        expect(await surface.renderedChildTitles('address')).toHaveLength(OWNED_ADDRESS_UIDS.length);
    });

    test('the heading follows the record through a reorder, and does not renumber', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        const before = await surface.renderedChildTitles('address');
        const moved = await surface.childTitle('address:3').innerText();
        expect(before[1], 'the fixture puts address 3 second').toEqual(moved);

        await surface.moveChild('address:3', 'Move up');
        expect(await surface.renderedChildUids('address')).toEqual([3, 2, 1, 4]);

        // The same heading, now first. A positional label would have swapped the
        // two texts instead of moving one of them, so this is the assertion that
        // separates "named by content" from "named by position".
        await expect(surface.childTitle('address:3')).toHaveText(moved);
        const after = await surface.renderedChildTitles('address');
        expect(after).toEqual([before[1], before[0], before[2], before[3]]);
    });
});
