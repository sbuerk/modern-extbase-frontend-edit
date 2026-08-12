/**
 * The editing surface, addressed the way it is built rather than the way it
 * happens to look.
 *
 * Every control the component draws lives in a shadow root, and Playwright's
 * CSS engine pierces open shadow roots - so the selectors below read like
 * ordinary ones. What they are anchored on is deliberate:
 *
 * - **fields by `data-focus`**, which is `<targetKey>|<field>` and is the same
 *   string `profileEdit.ts` uses to move the focus. It is a structural
 *   identifier, so a field's label may be retranslated without touching a spec.
 * - **buttons by their accessible name**, which is the label the server
 *   translated and handed over in `data-labels`. Addressing them by position
 *   would pass for a surface that renders "Remove" where "Hide" belongs.
 *
 * The fixture records these specs are written against are the ones of
 * `Tests/Functional/Fixtures/Database/ProfilePlugins.csv`: profile 1, "Ada
 * Lovelace", owned by frontend user 1, with four addresses - uid 4 hidden - and
 * two e-mail addresses.
 */
import type { Locator, Page, Response } from '@playwright/test';
import { expect } from '@playwright/test';
import * as path from 'node:path';
import { manifest } from '../manifest';

export const PROFILE_TABLE = 'tx_modernextbasefrontendedit_domain_model_profile';
export const ADDRESS_TABLE = 'tx_modernextbasefrontendedit_domain_model_address';
export const EMAIL_TABLE = 'tx_modernextbasefrontendedit_domain_model_email';

/**
 * The profile of the `owner` role, i.e. the record every spec edits.
 */
export const OWNED_PROFILE_UID = 1;

/**
 * The addresses of {@see OWNED_PROFILE_UID} in stored sorting order, the hidden
 * uid 4 included - which is the order the surface renders, because the edit
 * repositories deliberately do not hide the owner's hidden records.
 */
export const OWNED_ADDRESS_UIDS = [2, 3, 1, 4];

/**
 * The e-mail addresses of {@see OWNED_PROFILE_UID} in stored sorting order.
 */
export const OWNED_EMAIL_UIDS = [2, 1];

export type ChildType = 'address' | 'email';

/**
 * A record the surface can edit, spelled the way `recordTarget.ts` spells it.
 */
export type Target = 'profile' | `${ChildType}:${number}` | `${ChildType}:new`;

/**
 * The endpoint actions a spec waits for. `read` is absent from the component's
 * endpoint map on purpose and can therefore never be observed here.
 */
export type EndpointAction =
    | 'save'
    | 'saveField'
    | 'addChild'
    | 'removeChild'
    | 'reorderChildren'
    | 'setChildVisibility'
    | 'uploadImage'
    | 'removeImage';

/**
 * The image the upload spec picks.
 *
 * The very file the functional suite uploads, addressed in the repository
 * rather than copied here: the Playwright container has the same bind mount as
 * the PHP containers, and a second copy of a binary fixture is a second thing
 * to keep in sync.
 */
export const FIXTURE_IMAGE_PATH = path.resolve(
    __dirname,
    '../../Functional/Fixtures/Files/profile-image.png',
);

export class ProfileEditPage {
    public constructor(private readonly page: Page) {}

    /**
     * The custom element. Present in the markup whether or not it upgraded,
     * which is what makes it usable for the degradation spec as well.
     */
    public get element(): Locator {
        return this.page.locator('modern-extbase-frontend-edit-profile');
    }

    public async open(): Promise<void> {
        await this.page.goto(manifest.editPagePath);
    }

    /**
     * Waits until the element has upgraded and rendered its own surface.
     *
     * "Upgraded" is asserted through something only the shadow root has: the
     * light DOM stays in the document either way, so waiting for the profile
     * heading would be satisfied by a page that never enhanced at all.
     */
    public async waitForEnhancement(): Promise<void> {
        await expect(this.field('profile', 'firstname')).toBeVisible();
    }

    public field(target: Target, name: string): Locator {
        return this.element.locator(
            `modern-extbase-frontend-edit-field[data-focus="${target}|${name}"]`,
        );
    }

    /**
     * The value a field shows while it is *not* being edited.
     */
    public displayedValue(target: Target, name: string): Locator {
        return this.field(target, name).locator('.field-value');
    }

    /**
     * The control a field is edited with - input, textarea or select.
     */
    public control(target: Target, name: string): Locator {
        return this.field(target, name).locator('.field-control');
    }

    public fieldErrors(target: Target, name: string): Locator {
        return this.field(target, name).locator('.field-errors li');
    }

    public recordErrors(target: Target): Locator {
        return this.recordOf(target).locator('.errors li');
    }

    /**
     * The `.record` block of one record.
     *
     * The profile's is the first one rendered; a child's is the one inside the
     * list item that carries that child's fields.
     */
    public recordOf(target: Target): Locator {
        if (target === 'profile') {
            return this.element.locator('.record').first();
        }

        return this.childRow(target).locator('.record');
    }

    /**
     * The list item of one child record.
     */
    public childRow(target: Target): Locator {
        return this.element.locator('li.child').filter({
            has: this.page.locator(`[data-focus^="${target}|"]`),
        });
    }

    /**
     * The form that creates a child, addressed through a field of its own.
     *
     * It is a `div.child-new` rather than an `li.child`, so {@see childRow}
     * does not reach it - and both collections render one, which is why it is
     * filtered by the `new` target of the collection it belongs to instead of
     * taken by position.
     */
    public newChildForm(child: ChildType): Locator {
        return this.element.locator('.child-new').filter({
            has: this.page.locator(`[data-focus^="${child}:new|"]`),
        });
    }

    public async startFieldEdit(target: Target, name: string): Promise<void> {
        await this.field(target, name).getByRole('button', { name: 'Edit', exact: true }).click();
        await expect(this.control(target, name)).toBeVisible();
    }

    public async type(target: Target, name: string, value: string): Promise<void> {
        await this.control(target, name).fill(value);
    }

    /**
     * Picks a value of a `choice` control.
     *
     * Separate from {@see type} because `fill()` refuses a `<select>`, and
     * because the two do not even reach the component the same way: a select
     * reports through `change`, every other control through `input`.
     */
    public async choose(target: Target, name: string, value: string): Promise<void> {
        await this.control(target, name).selectOption(value);
    }

    /**
     * Applies one field and waits for the answer of the partial save endpoint.
     *
     * The response is awaited rather than the DOM, and that is the difference
     * between an assertion and a race: the component replaces its state when the
     * answer arrives, so anything read before that is the state before the save.
     */
    public async applyField(target: Target, name: string): Promise<Response> {
        return this.withEndpoint('saveField', async (): Promise<void> => {
            await this.field(target, name).getByRole('button', { name: 'Apply', exact: true }).click();
        });
    }

    public async cancelField(target: Target, name: string): Promise<void> {
        await this.field(target, name).getByRole('button', { name: 'Cancel', exact: true }).click();
    }

    public async startRecordEdit(target: Target): Promise<void> {
        await this.recordOf(target).getByRole('button', { name: 'Edit all fields', exact: true }).click();
    }

    public async submitRecord(target: Target): Promise<Response> {
        return this.withEndpoint('save', async (): Promise<void> => {
            await this.recordOf(target).getByRole('button', { name: 'Save all fields', exact: true }).click();
        });
    }

    /**
     * Submits the add form of one collection.
     *
     * There is no per field apply on that form - the record does not exist yet -
     * so the whole form is submitted by its own button.
     */
    public async addChild(child: ChildType): Promise<Response> {
        return this.withEndpoint('addChild', async (): Promise<void> => {
            await this.newChildForm(child).getByRole('button', { name: 'Add', exact: true }).click();
        });
    }

    public async removeChild(target: Target): Promise<Response> {
        return this.withEndpoint('removeChild', async (): Promise<void> => {
            await this.childRow(target).getByRole('button', { name: 'Remove', exact: true }).click();
        });
    }

    public async moveChild(target: Target, direction: 'Move up' | 'Move down'): Promise<Response> {
        return this.withEndpoint('reorderChildren', async (): Promise<void> => {
            await this.childRow(target).getByRole('button', { name: direction, exact: true }).click();
        });
    }

    /**
     * The uids of one collection in the order the surface renders them.
     *
     * Read off the `data-focus` attributes rather than off any text, because the
     * order is the assertion and the text is not. The add form carries the same
     * attribute shape with `new` instead of a uid and is dropped.
     */
    /**
     * The image element of the enhanced surface.
     *
     * Everything below it lives in its shadow root, which Playwright pierces —
     * so `imageControl()` and `enhancedImage()` read like ordinary selectors.
     */
    public get imageElement(): Locator {
        return this.element.locator('modern-extbase-frontend-edit-image');
    }

    /**
     * The file input. There is exactly one control per image and it has no
     * `Apply` — picking a file *is* the write.
     */
    public get imageControl(): Locator {
        return this.imageElement.locator('input.field-control');
    }

    /**
     * The `<img>` the component draws, i.e. the image as the surface believes
     * it to be right now.
     */
    public get enhancedImage(): Locator {
        return this.imageElement.locator('img');
    }

    /**
     * The `<figure>` the **server** rendered into the markup, from
     * `Profile/Image.html`.
     *
     * This is the no-JavaScript view, and it is the one that answers "the new
     * image is served": it is part of the document the server sent, so it says
     * nothing about what the component did after the page loaded. The class is
     * the partial's own and does not occur in the shadow root, so the two cannot
     * be confused.
     */
    public get servedImage(): Locator {
        return this.element.locator('figure.modern-extbase-frontend-edit-profile-image img');
    }

    public get imageErrors(): Locator {
        return this.imageElement.locator('.field-errors li');
    }

    /**
     * Picks a file and waits for the upload to be answered.
     *
     * `setInputFiles()` dispatches the `change` event the component listens for,
     * which is what makes this the same interaction a user performs — there is
     * no submit button to press afterwards.
     */
    public async uploadImage(filePath: string = FIXTURE_IMAGE_PATH): Promise<Response> {
        return this.withEndpoint('uploadImage', async (): Promise<void> => {
            await this.imageControl.setInputFiles(filePath);
        });
    }

    public async removeImage(): Promise<Response> {
        return this.withEndpoint('removeImage', async (): Promise<void> => {
            await this.imageElement.getByRole('button', { name: 'Remove', exact: true }).click();
        });
    }

    public async renderedChildUids(child: ChildType): Promise<number[]> {
        const keys = await this.element
            .locator(`modern-extbase-frontend-edit-field[data-focus^="${child}:"][data-focus$="|type"]`)
            .evaluateAll((elements: Element[]): string[] =>
                elements.map((element: Element): string => element.getAttribute('data-focus') ?? ''));

        return keys
            .map((key: string): string => key.slice(child.length + 1, key.indexOf('|')))
            .filter((uid: string): boolean => uid !== 'new')
            .map((uid: string): number => Number(uid));
    }

    /**
     * Where the focus is, expressed in the surface's own terms.
     *
     * `document.activeElement` answers with the *host* of a shadow root, so a
     * naive read reports the custom element for every control it contains. This
     * walks into the shadow roots and then back up to the field element, which
     * is what "the focus is in this field's control" means.
     */
    public async focusedField(): Promise<{ field: string | null; control: string | null }> {
        return this.page.evaluate((): { field: string | null; control: string | null } => {
            let active: Element | null = document.activeElement;
            while (active?.shadowRoot?.activeElement) {
                active = active.shadowRoot.activeElement;
            }
            if (active === null) {
                return { field: null, control: null };
            }

            let node: Node | null = active;
            while (node !== null) {
                if (node instanceof Element && node.tagName === 'MODERN-EXTBASE-FRONTEND-EDIT-FIELD') {
                    return { field: node.getAttribute('data-focus'), control: active.tagName.toLowerCase() };
                }
                node = node.parentNode instanceof ShadowRoot ? node.parentNode.host : node.parentNode;
            }

            return { field: null, control: active.tagName.toLowerCase() };
        });
    }

    /**
     * Runs an interaction and waits for the endpoint it is supposed to call.
     *
     * The wait is registered *before* the interaction, so a fast answer cannot
     * be missed. The action travels in the query string - it is part of the
     * cHash, which is why the endpoint map is six finished URLs - so it can be
     * matched on the URL alone.
     */
    private async withEndpoint(action: EndpointAction, interaction: () => Promise<void>): Promise<Response> {
        const pending = this.page.waitForResponse((response: Response): boolean =>
            response.url().includes(`%5Baction%5D=${action}`));
        await interaction();

        return pending;
    }
}
