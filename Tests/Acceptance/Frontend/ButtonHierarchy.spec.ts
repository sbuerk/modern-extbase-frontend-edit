/**
 * Which button carries which emphasis.
 *
 * This is the one appearance concern in the suite, and it is here rather than
 * left to a person looking at a screenshot because what it pins is not a colour
 * — it is a **mapping from intent to emphasis**. `data-variant="primary"` means
 * "this commits the pending change" and `danger` means "this destroys a record
 * or a file"; the stylesheet then decides what either looks like, and may change
 * that freely without touching this file.
 *
 * The test that matters is the last one. The first two would pass a surface that
 * marked every button `primary`, and the risk worth guarding against is not a
 * missing attribute on a button that exists today — it is the next button, added
 * without anyone deciding what it is. Enumerating every button and asserting the
 * complete mapping means a new one fails here until that decision is made, which
 * is the forcing function this file exists to be.
 *
 * Buttons are addressed by accessible name for the same reason the page object
 * does it: it is the label the server translated, and addressing by position
 * would pass for a surface that draws `Remove` where `Hide` belongs.
 */
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';

/**
 * Every button the surface draws for the fixture profile, and the emphasis each
 * one is supposed to carry. `null` is the unmarked default, and it is spelled
 * out rather than omitted so that "nobody decided" and "decided: default" are
 * distinguishable when this list is next edited.
 *
 * `Hide` and `Show` are both listed and both occur: they are one button with two
 * labels, and the fixture has a hidden address alongside five visible children,
 * so the surface renders five of the first and one of the second.
 */
const expectedVariants: ReadonlyMap<string, string | null> = new Map([
    ['Edit', null],
    ['Edit all fields', null],
    ['Apply', 'primary'],
    ['Save all fields', 'primary'],
    ['Add', 'primary'],
    ['Cancel', null],
    ['Move up', null],
    ['Move down', null],
    // Reordering is never emphasised and never destructive, however far it
    // moves a record: it changes presentation and nothing else, and it is
    // reversible by doing it again.
    ['Move to top', null],
    ['Move to bottom', null],
    ['Hide', null],
    ['Show', null],
    ['Remove', 'danger'],
]);

test.describe('Button hierarchy', (): void => {
    test('the button that commits a pending change is the emphasised one', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();
        await surface.startFieldEdit('profile', 'firstname');

        const field = surface.field('profile', 'firstname');
        await expect(field.getByRole('button', { name: 'Apply', exact: true }))
            .toHaveAttribute('data-variant', 'primary');
        // Cancelling is an ordinary thing to want, and is not de-emphasised.
        await expect(field.getByRole('button', { name: 'Cancel', exact: true }))
            .not.toHaveAttribute('data-variant', /.*/);
    });

    test('the buttons that destroy something are marked as destructive', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // The child record and the profile image are the two things the surface
        // can destroy, and they are rendered by different components.
        await expect(surface.childRow('address:2').getByRole('button', { name: 'Remove', exact: true }))
            .toHaveAttribute('data-variant', 'danger');
        await expect(surface.imageElement.getByRole('button', { name: 'Remove', exact: true }))
            .toHaveAttribute('data-variant', 'danger');
    });

    test('every button the surface draws has a decided emphasis', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();
        // Open both editing modes so that the buttons only a session renders -
        // Apply, Cancel, Save all fields - are in the document as well.
        await surface.startRecordEdit('profile');
        await surface.startFieldEdit('address:2', 'line1');

        const drawn = await surface.element.locator('button').evaluateAll(
            (buttons: Element[]): { name: string; variant: string | null }[] =>
                buttons.map((button: Element): { name: string; variant: string | null } => ({
                    name: (button.textContent ?? '').trim(),
                    variant: button.getAttribute('data-variant'),
                })),
        );

        expect(drawn.length).toBeGreaterThan(0);
        const undecided = drawn.filter(({ name }): boolean => !expectedVariants.has(name));
        expect(
            undecided.map(({ name }): string => name),
            'a button whose emphasis nobody decided - add it to expectedVariants',
        ).toEqual([]);

        const wrong = drawn.filter(({ name, variant }): boolean =>
            (expectedVariants.get(name) ?? null) !== variant);
        expect(
            wrong.map(({ name, variant }): string => `${name}: ${variant ?? 'default'}`),
            'a button carrying an emphasis it was not given',
        ).toEqual([]);
    });
});
