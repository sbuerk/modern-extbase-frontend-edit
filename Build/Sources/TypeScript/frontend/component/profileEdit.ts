/**
 * The editing surface of one profile.
 *
 * ## Progressive enhancement, and what "enhance" means here
 *
 * The server renders the profile as readable markup **inside** this element.
 * Without JavaScript, without the import map resolving, with a malformed
 * attribute or with a browser that does not support custom elements, that
 * markup is what the visitor sees — the element is an unknown tag with children
 * and nothing more.
 *
 * When the element does upgrade, it renders the editable surface into its
 * shadow root and does **not** slot its light DOM children, so the server
 * rendered view disappears in favour of the editable one. That is a deliberate
 * choice over enhancing the existing markup in place, for one reason that
 * cannot be designed around: adding, removing and reordering children produces
 * records the server never rendered markup for, so the collections have to be
 * rendered from state anyway. One rendering mechanism that always shows the
 * server's state beats two that can disagree with each other.
 *
 * The refusal path keeps that promise: when the attributes do not yield a
 * profile, an endpoint map and a token, {@see render} returns a bare `<slot>`
 * and the server rendered markup stays visible. Nothing is half enhanced.
 *
 * ## Where the state lives
 *
 * Two reactive properties, and neither is a model of what the user is doing to
 * the record:
 *
 * - `profile` — the last server known document. Replaced wholesale by every
 *   successful response, never patched. Rendering reads values from it, so a
 *   value the server normalised becomes visible without anything comparing it
 *   with what was sent.
 * - `edits` — which fields are open, what is typed in them, and what the server
 *   said about them. Cancelling drops the entry and the field falls back to the
 *   value in `profile`, which is what "cancel restores the last server known
 *   value" means once one save has already succeeded.
 *
 * The image adds one flag, `imageRejected`, and deliberately nothing else. It is
 * not an edit session: a file has no draft to keep, so what has to be remembered
 * about a failed upload is not a value but the fact that the file is gone and
 * has to be picked again.
 *
 * ## The data it needs
 *
 * Four attributes, all rendered by the Fluid template — there is no inline
 * script, and no `JavaScriptModuleInstruction`:
 *
 * | Attribute        | Content                                                        |
 * |------------------|----------------------------------------------------------------|
 * | `data-profile`   | the `data` document of the endpoints, as JSON                  |
 * | `data-endpoints` | one `UriBuilder` built URL per action, as JSON                 |
 * | `data-token`     | the request token JWT, sent as `X-TYPO3-RequestToken`          |
 * | `data-labels`    | every user facing string, translated, as JSON                  |
 */
import { css, html, LitElement, nothing } from 'lit';
import type { TemplateResult } from 'lit';
import { customElement, state } from 'lit/decorators.js';
import { repeat } from 'lit/directives/repeat.js';
import type { ChildRecord, ChildType, ProfileRecord } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/types.js';
import { childTypes } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/types.js';
import type { RecordTarget } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/recordTarget.js';
import {
    childTarget,
    isNewChildTarget,
    newChildTarget,
    profileTarget,
    targetKey,
    targetScope,
} from '@sbuerk/modern-extbase-frontend-edit/frontend/model/recordTarget.js';
import type { FieldDefinition } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/fieldDefinitions.js';
import { fieldsOf, fieldsOfChild, initialValues } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/fieldDefinitions.js';
import {
    childrenOf,
    childUids,
    displayName,
    fieldValue,
    isChildHidden,
    movedChildOrder,
    parseProfileRecord,
    recordValues,
} from '@sbuerk/modern-extbase-frontend-edit/frontend/model/profileRecord.js';
import { imageField } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/imageEdit.js';
import type { RecordEdit } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/editState.js';
import { EditSessions } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/editState.js';
import type { LabelMap } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js';
import { actionLabelKey, label, parseLabels, sectionLabelKey, stateLabelKey } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js';
import { readJson } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/json.js';
import type { EndpointAction } from '@sbuerk/modern-extbase-frontend-edit/frontend/api/endpoints.js';
import { parseEndpoints } from '@sbuerk/modern-extbase-frontend-edit/frontend/api/endpoints.js';
import type { Payload, RequestBody } from '@sbuerk/modern-extbase-frontend-edit/frontend/api/payload.js';
import {
    addChildPayload,
    fieldPayload,
    imageUploadBody,
    recordPayload,
    removeChildPayload,
    removeImagePayload,
    reorderPayload,
    visibilityPayload,
} from '@sbuerk/modern-extbase-frontend-edit/frontend/api/payload.js';
import type { EndpointResult } from '@sbuerk/modern-extbase-frontend-edit/frontend/api/response.js';
import { ProfileEndpointClient } from '@sbuerk/modern-extbase-frontend-edit/frontend/api/client.js';
import { controls } from '@sbuerk/modern-extbase-frontend-edit/frontend/style/controls.js';
import { tokens } from '@sbuerk/modern-extbase-frontend-edit/frontend/style/tokens.js';
import '@sbuerk/modern-extbase-frontend-edit/frontend/component/editField.js';
import '@sbuerk/modern-extbase-frontend-edit/frontend/component/editImage.js';

/**
 * What the focus mechanism needs of a rendered control, and all it needs.
 *
 * Both child elements satisfy it — a field and the image — so one query and one
 * `data-focus` attribute cover the whole surface. Typed structurally rather than
 * as a union, because what matters here is not which element it is.
 */
interface FocusableControl extends HTMLElement {
    readonly updateComplete: Promise<boolean>;
    focusControl(): void;
}

@customElement('modern-extbase-frontend-edit-profile')
export class ProfileEditElement extends LitElement {
    /*
     * This element is the only one that carries {@see tokens}, and that is a
     * requirement rather than a tidy-up — the token module's docblock explains
     * why declaring them on a child would break a site's ability to override
     * them.
     */
    public static override readonly styles = [
        tokens,
        controls,
        css`
            :host {
                display: block;
                max-width: var(--frontend-edit-measure);
            }

            .record {
                display: grid;
                gap: var(--frontend-edit-space-sm);
                padding: var(--frontend-edit-space-sm) 0;
            }

            .record-actions,
            .child-actions {
                display: flex;
                flex-wrap: wrap;
                gap: var(--frontend-edit-space-sm);
                align-items: center;
            }

            /*
             * A hairline in the border colour rather than in "currentColor",
             * which drew a rule as dark as the body text and made the separator
             * louder than the records it separates.
             */
            .children {
                border-top: var(--frontend-edit-border-width) solid var(--frontend-edit-color-border);
                margin-top: var(--frontend-edit-space-lg);
                padding-top: var(--frontend-edit-space-md);
            }

            .children-list {
                list-style: none;
                margin: 0;
                padding: 0;
                display: grid;
                gap: var(--frontend-edit-space-md);
            }

            /*
             * A child is one record inside another, and the marker on its
             * leading edge is what says so. It is the accent rather than the
             * text colour because it is a structural cue and not content.
             */
            .child {
                border-inline-start: 3px solid var(--frontend-edit-color-border);
                border-radius: 0 var(--frontend-edit-radius) var(--frontend-edit-radius) 0;
                padding-inline-start: var(--frontend-edit-space-md);
            }

            /*
             * The add form is a record that does not exist yet, and the dashed
             * edge is the whole statement: same shape, not yet real.
             */
            .child-new {
                border-inline-start-style: dashed;
                border-inline-start-color: var(--frontend-edit-color-border-strong);
            }

            /*
             * A state is a badge, not prose: it labels the record it sits beside
             * rather than telling the reader something new, so it is set small,
             * spaced out and quiet.
             */
            .state {
                align-self: center;
                border: var(--frontend-edit-border-width) solid var(--frontend-edit-color-border);
                border-radius: var(--frontend-edit-radius);
                padding: 0 var(--frontend-edit-space-xs);
                font-size: var(--frontend-edit-font-size-sm);
                color: var(--frontend-edit-color-muted);
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            .errors {
                margin: 0;
                padding: 0;
                list-style: none;
                color: var(--frontend-edit-color-danger);
                font-size: var(--frontend-edit-font-size-sm);
            }
        `,
    ];

    /**
     * The last server known document, and the only thing rendering reads
     * values from. `null` means the element did not enhance anything.
     */
    @state()
    private profile: ProfileRecord | null = null;

    @state()
    private edits: EditSessions = EditSessions.empty();

    @state()
    private labels: LabelMap = {};

    /**
     * Whether the last thing done to the image failed.
     *
     * Not part of the edit sessions, because it is not an error *about a value*
     * — it is the statement that the file the user picked no longer exists
     * anywhere and has to be picked again. It is cleared when the next attempt
     * starts, so it always describes the most recent one.
     */
    @state()
    private imageRejected = false;

    private client: ProfileEndpointClient | null = null;

    /**
     * The field that takes the focus after the next render, as
     * `<targetKey>|<field>`.
     *
     * Focus cannot be taken during a handler: the control is rendered by the
     * update the handler triggers and does not exist yet when the handler runs.
     */
    private pendingFocus: string | null = null;

    public override connectedCallback(): void {
        super.connectedCallback();
        this.initialize();
    }

    public override render(): TemplateResult {
        const profile = this.profile;
        if (profile === null) {
            // Not enhanced: the server rendered markup is the page.
            return html`<slot></slot>`;
        }

        return html`
            ${this.renderRecord(profile, profileTarget)}
            ${childTypes.map((child: ChildType): TemplateResult => this.renderChildren(profile, child))}
        `;
    }

    protected override updated(): void {
        const key = this.pendingFocus;
        if (key === null) {
            return;
        }
        this.pendingFocus = null;
        const field = this.renderRoot.querySelector<FocusableControl>(`[data-focus="${key}"]`);
        if (field === null) {
            return;
        }
        void field.updateComplete.then((): void => {
            field.focusControl();
        });
    }

    /**
     * Reads the four attributes, and enhances only when all of them are usable.
     *
     * All or nothing on purpose. A surface with a profile but no endpoints
     * would render controls that cannot save, and one with endpoints but no
     * token would render controls whose every save answers `403`. Both are
     * worse than the server rendered view, which is still in the light DOM and
     * which is what {@see render} falls back to.
     */
    private initialize(): void {
        if (this.client !== null) {
            // Already enhanced: connectedCallback runs again whenever the
            // element is moved in the document.
            return;
        }
        const profile = parseProfileRecord(readJson(this.getAttribute('data-profile')));
        const endpoints = parseEndpoints(readJson(this.getAttribute('data-endpoints')));
        const token = this.getAttribute('data-token') ?? '';
        if (profile === null || endpoints === null || token === '') {
            return;
        }
        this.labels = parseLabels(readJson(this.getAttribute('data-labels')));
        this.client = new ProfileEndpointClient(endpoints, token);
        this.profile = profile;
    }

    private renderRecord(profile: ProfileRecord, target: RecordTarget): TemplateResult {
        const edit = this.edits.of(target);

        return html`
            <div class="record">
                <div class="record-actions">
                    ${this.renderRecordActions(profile, target, edit)}
                    ${target.child === null && profile.hidden
                        ? html`<span class="state">${this.text(stateLabelKey('hidden'))}</span>`
                        : nothing}
                </div>
                ${this.renderGeneralErrors(target)}
                ${target.child === null ? this.renderImage(profile, target, edit) : nothing}
                ${fieldsOf(target).map((definition: FieldDefinition): TemplateResult =>
                    this.renderField(profile, target, definition, edit))}
            </div>
        `;
    }

    /**
     * The image, which the surface renders for the profile and for nothing else.
     *
     * It is inside the element, and it was outside it until the two image
     * endpoints existed. That earlier placement rested on one argument — "no
     * endpoint manages it, so nothing can make the rendered image disagree with
     * the server" — and the argument stops holding the moment something does
     * manage it. An image left outside would keep showing the file the page was
     * loaded with after the first upload, which is exactly the defect the name
     * heading is inside to avoid.
     *
     * It is not a `FieldDefinition` and does not appear in `fieldsOf()`: those
     * are the fields a `save` carries, and the image is written by neither
     * `save` nor `saveField`. Putting it in that list would make every whole
     * record edit open a control for a value it cannot submit.
     */
    private renderImage(profile: ProfileRecord, target: RecordTarget, edit: RecordEdit | null): TemplateResult {
        return html`
            <modern-extbase-frontend-edit-image
                data-focus="${focusKey(target, imageField)}"
                .image="${profile.image}"
                .labels="${this.labels}"
                .profileName="${displayName(profile)}"
                .busy="${edit?.busy ?? false}"
                .errors="${this.edits.errorsOf(target, imageField)}"
                .rejected="${this.imageRejected}"
                @image-select="${(event: CustomEvent<{ file: File }>): void =>
                    void this.uploadImage(event.detail.file)}"
                @image-remove="${(): void => void this.clearImage()}"
            ></modern-extbase-frontend-edit-image>
        `;
    }

    private renderRecordActions(
        profile: ProfileRecord,
        target: RecordTarget,
        edit: RecordEdit | null,
    ): TemplateResult {
        if (edit?.mode === 'record') {
            return html`
                <button type="button" ?disabled="${edit.busy}" @click="${(): void => void this.submitRecord(target)}">
                    ${this.text(actionLabelKey('save'))}
                </button>
                <button type="button" ?disabled="${edit.busy}" @click="${(): void => this.cancelRecord(target)}">
                    ${this.text(actionLabelKey('cancel'))}
                </button>
            `;
        }

        return html`
            <button
                type="button"
                ?disabled="${edit?.busy ?? false}"
                @click="${(): void => this.beginRecord(profile, target)}"
            >
                ${this.text(actionLabelKey('editRecord'))}
            </button>
        `;
    }

    private renderField(
        profile: ProfileRecord,
        target: RecordTarget,
        definition: FieldDefinition,
        edit: RecordEdit | null,
    ): TemplateResult {
        const field = definition.name;
        const stored = fieldValue(profile, target, field);

        return html`
            <modern-extbase-frontend-edit-field
                data-focus="${focusKey(target, field)}"
                .definition="${definition}"
                .scope="${targetScope(target)}"
                .labels="${this.labels}"
                .serverValue="${stored}"
                .draftValue="${this.edits.draftOf(target, field, stored)}"
                .editing="${edit?.fields.includes(field) ?? false}"
                .busy="${edit?.busy ?? false}"
                .recordMode="${edit?.mode === 'record'}"
                .errors="${this.edits.errorsOf(target, field)}"
                @field-edit="${(): void => this.beginField(profile, target, field)}"
                @field-input="${(event: CustomEvent<{ value: string }>): void =>
                    this.onInput(target, field, event.detail.value)}"
                @field-apply="${(): void => void this.applyField(target, field)}"
                @field-cancel="${(): void => this.cancelField(target, field)}"
            ></modern-extbase-frontend-edit-field>
        `;
    }

    private renderChildren(profile: ProfileRecord, child: ChildType): TemplateResult {
        const records = childrenOf(profile, child);

        return html`
            <section class="children">
                <h3>${this.text(sectionLabelKey(child))}</h3>
                <ol class="children-list">
                    ${repeat(
                        records,
                        (record: ChildRecord): number => record.uid,
                        (record: ChildRecord, index: number): TemplateResult =>
                            this.renderChild(profile, child, record, index, records.length),
                    )}
                </ol>
                ${this.renderNewChild(child)}
            </section>
        `;
    }

    private renderChild(
        profile: ProfileRecord,
        child: ChildType,
        record: ChildRecord,
        index: number,
        total: number,
    ): TemplateResult {
        const target = childTarget(child, record.uid);
        const busy = this.edits.isBusy(target);
        const hidden = isChildHidden(profile, child, record.uid);

        return html`
            <li class="child">
                ${this.renderRecord(profile, target)}
                <div class="child-actions">
                    <button
                        type="button"
                        ?disabled="${busy || index === 0}"
                        @click="${(): void => void this.moveChild(child, record.uid, -1)}"
                    >
                        ${this.text(actionLabelKey('moveUp'))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${busy || index === total - 1}"
                        @click="${(): void => void this.moveChild(child, record.uid, 1)}"
                    >
                        ${this.text(actionLabelKey('moveDown'))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${busy}"
                        @click="${(): void => void this.setChildVisibility(child, record.uid, !hidden)}"
                    >
                        ${this.text(actionLabelKey(hidden ? 'show' : 'hide'))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${busy}"
                        @click="${(): void => void this.deleteChild(child, record.uid)}"
                    >
                        ${this.text(actionLabelKey('remove'))}
                    </button>
                    ${hidden ? html`<span class="state">${this.text(stateLabelKey('hidden'))}</span>` : nothing}
                </div>
            </li>
        `;
    }

    /**
     * The form that creates a child.
     *
     * Its fields are always switched to a control and carry no per field apply,
     * because there is nothing to apply *to* yet: the record does not exist
     * until the whole form is submitted. The drafts live in the same session
     * machinery as every other record, under a target whose `childUid` is
     * `null`, so a `422` from `addChild` lands at the field exactly like one
     * from a save.
     */
    private renderNewChild(child: ChildType): TemplateResult {
        const target = newChildTarget(child);
        const defaults = initialValues(fieldsOfChild(child));
        const edit = this.edits.of(target);

        return html`
            <div class="child child-new">
                ${this.renderGeneralErrors(target)}
                ${fieldsOfChild(child).map((definition: FieldDefinition): TemplateResult => {
                    const field = definition.name;

                    return html`
                        <modern-extbase-frontend-edit-field
                            data-focus="${focusKey(target, field)}"
                            .definition="${definition}"
                            .scope="${child}"
                            .labels="${this.labels}"
                            .serverValue="${''}"
                            .draftValue="${this.edits.draftOf(target, field, defaults[field] ?? '')}"
                            .editing="${true}"
                            .busy="${edit?.busy ?? false}"
                            .recordMode="${true}"
                            .errors="${this.edits.errorsOf(target, field)}"
                            @field-input="${(event: CustomEvent<{ value: string }>): void =>
                                this.onInput(target, field, event.detail.value)}"
                            @field-apply="${(): void => void this.addChild(child)}"
                            @field-cancel="${(): void => this.cancelRecord(target)}"
                        ></modern-extbase-frontend-edit-field>
                    `;
                })}
                <div class="child-actions">
                    <button type="button" ?disabled="${edit?.busy ?? false}" @click="${(): void => void this.addChild(child)}">
                        ${this.text(actionLabelKey('add'))}
                    </button>
                </div>
            </div>
        `;
    }

    private renderGeneralErrors(target: RecordTarget): TemplateResult | typeof nothing {
        const messages = this.edits.generalErrorsOf(target);
        if (messages.length === 0) {
            return nothing;
        }

        return html`
            <ul class="errors" role="alert">
                ${messages.map((message: string): TemplateResult => html`<li>${message}</li>`)}
            </ul>
        `;
    }

    private beginField(profile: ProfileRecord, target: RecordTarget, field: string): void {
        this.edits = this.edits.beginField(target, field, fieldValue(profile, target, field));
        this.pendingFocus = focusKey(target, field);
    }

    private onInput(target: RecordTarget, field: string, value: string): void {
        this.edits = this.edits.setDraft(target, field, value);
    }

    /**
     * Cancel: the draft is discarded and the field shows the stored value again.
     *
     * In a whole record edit the whole session goes, because cancelling one
     * control of a form that submits together is not a state the user asked
     * for.
     */
    private cancelField(target: RecordTarget, field: string): void {
        if (this.edits.of(target)?.mode === 'record') {
            this.cancelRecord(target);

            return;
        }
        this.edits = this.edits.endField(target, field);
    }

    /**
     * Apply: sends **only this field**, through the partial save endpoint.
     */
    private async applyField(target: RecordTarget, field: string): Promise<void> {
        if (isNewChildTarget(target)) {
            await this.addChild(target.child);

            return;
        }
        const edit = this.edits.of(target);
        if (edit === null || edit.busy) {
            return;
        }
        if (edit.mode === 'record') {
            await this.submitRecord(target);

            return;
        }
        const value = this.edits.draftOf(target, field, '');
        await this.send(
            target,
            'saveField',
            (profile: ProfileRecord): Payload => fieldPayload(profile.uid, target, field, value),
            (): void => {
                this.edits = this.edits.endField(target, field);
            },
        );
    }

    private beginRecord(profile: ProfileRecord, target: RecordTarget): void {
        const values = recordValues(profile, target);
        this.edits = this.edits.beginRecord(target, values);
        const first = Object.keys(values).at(0);
        this.pendingFocus = first === undefined ? null : focusKey(target, first);
    }

    private cancelRecord(target: RecordTarget): void {
        this.edits = this.edits.endRecord(target);
    }

    private async submitRecord(target: RecordTarget): Promise<void> {
        const edit = this.edits.of(target);
        if (edit === null || edit.busy) {
            return;
        }
        const data = this.draftValues(target);
        await this.send(
            target,
            'save',
            (profile: ProfileRecord): Payload => recordPayload(profile.uid, target, data),
            (): void => {
                this.edits = this.edits.endRecord(target);
            },
        );
    }

    private async addChild(child: ChildType): Promise<void> {
        const target = newChildTarget(child);
        if (this.edits.isBusy(target)) {
            return;
        }
        const data = this.draftValues(target);
        await this.send(
            target,
            'addChild',
            (profile: ProfileRecord): Payload => addChildPayload(profile.uid, child, data),
            (): void => {
                // Drops the drafts, so the form goes back to the values a new
                // record starts from.
                this.edits = this.edits.endRecord(target);
            },
        );
    }

    /**
     * Removes one child — and the endpoint deletes the row rather than only
     * detaching it, which would leave it behind with no parent and no sorting.
     *
     * Named `deleteChild` because `removeChild` is `Node.removeChild()`, which
     * this element inherits.
     */
    private async deleteChild(child: ChildType, childUid: number): Promise<void> {
        const target = childTarget(child, childUid);
        await this.send(
            target,
            'removeChild',
            (profile: ProfileRecord): Payload => removeChildPayload(profile.uid, child, childUid),
            (): void => {
                this.edits = this.edits.endRecord(target);
            },
        );
    }

    /**
     * Moves one child, sending the **whole** resulting order.
     *
     * A move that would leave the collection produces the unchanged order, and
     * the request is then not sent at all — the endpoint would accept it and
     * write the same sorting back, which is a write nobody asked for.
     */
    private async moveChild(child: ChildType, childUid: number, offset: number): Promise<void> {
        const profile = this.profile;
        if (profile === null) {
            return;
        }
        const order = movedChildOrder(profile, child, childUid, offset);
        if (sameOrder(order, childUids(profile, child))) {
            return;
        }
        const target = childTarget(child, childUid);
        await this.send(
            target,
            'reorderChildren',
            (current: ProfileRecord): Payload => reorderPayload(current.uid, child, order),
            (): void => {
                this.pendingFocus = null;
            },
        );
    }

    private async setChildVisibility(child: ChildType, childUid: number, hidden: boolean): Promise<void> {
        const target = childTarget(child, childUid);
        await this.send(
            target,
            'setChildVisibility',
            (profile: ProfileRecord): Payload => visibilityPayload(profile.uid, child, childUid, hidden),
            (): void => {
                this.pendingFocus = null;
            },
        );
    }

    /**
     * Replaces the image with the picked file, through the one multipart request.
     *
     * The `File` is passed to the body builder and held nowhere: on a failure it
     * is gone from this component exactly as it is gone from the server, which
     * is what {@see imageRejected} then tells the user. Keeping it in order to
     * offer a retry without a re-pick was considered and rejected — a surface
     * that can re-send a file it does not show is a surface whose state the user
     * cannot see.
     */
    private async uploadImage(file: File): Promise<void> {
        this.imageRejected = false;
        const result = await this.send(
            profileTarget,
            'uploadImage',
            (profile: ProfileRecord): RequestBody => imageUploadBody(profile.uid, file),
            (): void => {
                this.pendingFocus = null;
            },
        );
        // Null is "the request was not made at all" — the surface is busy, or it
        // never enhanced — and nothing was lost in that case.
        this.imageRejected = result !== null && result.kind !== 'success';
    }

    /**
     * Removes the stored image.
     *
     * An ordinary JSON call: there is nothing to transfer, so nothing about
     * multipart applies. Named `clearImage` for the same reason `deleteChild` is
     * not `removeChild` — `removeImage` would read like a DOM method on an
     * element, and this class already inherits one collision of that kind.
     */
    private async clearImage(): Promise<void> {
        this.imageRejected = false;
        await this.send(
            profileTarget,
            'removeImage',
            (profile: ProfileRecord): RequestBody => removeImagePayload(profile.uid),
            (): void => {
                this.pendingFocus = null;
            },
        );
    }

    /**
     * One request, with the busy flag and the answer handling around it.
     *
     * The body is built from the profile as it is *when the request is sent*
     * rather than from one captured earlier, because a previous response may
     * have replaced the document in between.
     *
     * Answers the result, or `null` when no request was made — a caller that has
     * something to do with the outcome beyond what {@see applyResult} does with
     * it needs to tell those two apart.
     */
    private async send(
        target: RecordTarget,
        action: EndpointAction,
        body: (profile: ProfileRecord) => RequestBody,
        onSuccess: () => void,
    ): Promise<EndpointResult | null> {
        const client = this.client;
        const profile = this.profile;
        if (client === null || profile === null || this.edits.isBusy(target)) {
            return null;
        }
        this.edits = this.edits.clearErrors(target).setBusy(target, true);
        const result = await client.send(action, body(profile));
        this.edits = this.edits.setBusy(target, false);
        this.applyResult(result, target, onSuccess);

        return result;
    }

    /**
     * What the three possible answers do to the state.
     *
     * Success replaces the document — the persisted state, not what was sent —
     * and ends the session. A validation failure keeps the session, its drafts
     * included, puts the messages at their fields and moves the focus to the
     * first field the server complained about. Anything else is reported at the
     * record with a translated sentence of ours; the endpoint's own `message`
     * is written for a developer and is deliberately not shown.
     */
    private applyResult(result: EndpointResult, target: RecordTarget, onSuccess: () => void): void {
        if (result.kind === 'success') {
            this.profile = result.profile;
            this.edits = this.edits.clearErrors(target);
            onSuccess();

            return;
        }

        if (result.kind === 'validation') {
            this.edits = this.edits.applyErrors(target, result.fieldErrors, result.generalErrors);
            const first = Object.keys(result.fieldErrors).at(0);
            this.pendingFocus = first === undefined ? null : focusKey(target, first);

            return;
        }

        this.edits = this.edits.applyErrors(target, {}, [this.requestErrorText(result.status)]);
    }

    /**
     * Every value of a record's session, falling back to what a record of that
     * kind starts from.
     *
     * The fallback matters for the add form, whose session does not exist until
     * something is typed into it.
     */
    private draftValues(target: RecordTarget): Record<string, string> {
        const profile = this.profile;
        const defaults = initialValues(fieldsOf(target));
        const values: Record<string, string> = {};
        for (const definition of fieldsOf(target)) {
            const stored = profile === null || isNewChildTarget(target)
                ? defaults[definition.name] ?? ''
                : fieldValue(profile, target, definition.name);
            values[definition.name] = this.edits.draftOf(target, definition.name, stored);
        }

        return values;
    }

    /**
     * The sentence shown for a failure that is not a validation failure.
     *
     * A status specific label is used when the site provides one — `403` after
     * a session expired and `409` while a workspace is active are the two a
     * user can act on — and the generic one otherwise. Only `error.request` has
     * to exist.
     */
    private requestErrorText(status: number): string {
        const specific = `error.request.${status}`;

        return this.labels[specific] ?? this.text('error.request');
    }

    private text(key: string): string {
        return label(this.labels, key);
    }
}

function focusKey(target: RecordTarget, field: string): string {
    return `${targetKey(target)}|${field}`;
}

function sameOrder(one: readonly number[], other: readonly number[]): boolean {
    return one.length === other.length && one.every((uid: number, index: number): boolean => uid === other[index]);
}

declare global {
    interface HTMLElementTagNameMap {
        'modern-extbase-frontend-edit-profile': ProfileEditElement;
    }
}
