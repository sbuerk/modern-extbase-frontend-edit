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
import { html, LitElement, nothing } from 'lit';
import type { TemplateResult } from 'lit';
import { customElement, state } from 'lit/decorators.js';
import { repeat } from 'lit/directives/repeat.js';
import type { ChildRecord, ChildType, ProfileRecord } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/types.js';
import type { CollectionEnd } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/profileRecord.js';
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
    childOrderMovedToEnd,
    movedChildOrder,
    parseProfileRecord,
    recordValues,
} from '@sbuerk/modern-extbase-frontend-edit/frontend/model/profileRecord.js';
import { childIdentity } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/childIdentity.js';
import { imageField } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/imageEdit.js';
import type { RecordEdit } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/editState.js';
import { EditSessions } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/editState.js';
import type { LabelMap } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js';
import { actionLabelKey, choiceLabelKey, dialogTitleLabelKey, label, parseLabels, sectionLabelKey, stateLabelKey } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js';
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
import type { ComponentConfiguration, ElementType } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/componentConfiguration.js';
import { classesFor, emptyConfiguration, icon, parseComponentConfiguration } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/componentConfiguration.js';
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

/** See {@see ProfileEditElement.uid}. */
let instances = 0;

@customElement('modern-extbase-frontend-edit-profile')
export class ProfileEditElement extends LitElement {
    /**
     * Renders into the light DOM.
     *
     * This is the decision the whole styling layer turns on, and it reverses the
     * one this component shipped with. A shadow root gave the surface perfect
     * encapsulation and made it impossible for a site to style: selectors do not
     * cross the boundary, so a theme's own `.button` rules could never reach a
     * button in here and only inherited properties like `font-family` got
     * through. `::part()` would expose the elements and still not let a theme
     * *reuse* its existing rules on them.
     *
     * What is given up is real. The page's CSS now applies to everything the
     * surface draws, and a host page can break it exactly as it can break any
     * other markup on the page. That is the trade a proof of concept about
     * project-overridable editing should be making.
     *
     * Two consequences that are not obvious:
     *
     * - `static styles` is gone from all three components. Lit only adopts it
     *   into a shadow root, so leaving it would look like styling and do
     *   nothing. The appearance lives in
     *   `Build/Sources/Css/frontend/frontend-edit.css`.
     * - That stylesheet is **no longer optional**. It used to be a pure
     *   addition, with the tokens shipped inside the component precisely so a
     *   page that failed to load it still rendered a coherent surface. There is
     *   no such fallback now.
     */
    protected override createRenderRoot(): HTMLElement {
        return this;
    }

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
     * What the server resolved from
     * `$GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit']`: the icon
     * markup per action and the additional CSS classes per element type.
     *
     * Held here and handed down to the field and image elements as a property,
     * exactly as the labels are. Both are document-wide facts that every element
     * of one surface has to agree on, and re-reading the attribute in each child
     * would let two of them disagree after a change.
     */
    @state()
    private configuration: ComponentConfiguration = emptyConfiguration;

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

    /**
     * Unique per document, so two plugins on one page do not both claim the
     * same `aria-labelledby` target. The field and image elements each carry
     * their own counter for the same reason.
     */
    private readonly uid = `frontend-edit-profile-${++instances}`;

    /**
     * The collection whose add dialog should be open, or `null`.
     *
     * State rather than a call to `showModal()` at the click, because the
     * dialog is re-rendered on every state change - a keystroke in one of its
     * fields included - and lit reuses the element rather than replacing it.
     * Driving the open state from {@see updated} keeps "what should be open"
     * and "what is open" the same thing after any render.
     */
    @state()
    private addDialogFor: ChildType | null = null;

    public override connectedCallback(): void {
        super.connectedCallback();
        this.initialize();
    }

    public override render(): TemplateResult | typeof nothing {
        const profile = this.profile;
        if (profile === null) {
            /*
             * Not enhanced, so the server rendered markup is the page and has to
             * be left exactly as it is.
             *
             * `nothing` rather than the `<slot>` this returned under a shadow
             * root: there is no slot in the light DOM, and rendering nothing is
             * what leaves the existing children untouched. `lit-html` inserts
             * rather than clears, so "render nothing" really does mean "do not
             * touch the server's markup".
             */
            return nothing;
        }

        return html`
            ${this.renderRecord(profile, profileTarget)}
            ${childTypes.map((child: ChildType): TemplateResult => this.renderChildren(profile, child))}
        `;
    }

    /**
     * Opens or closes the add dialog to match {@see addDialogFor}.
     *
     * `showModal()` throws if the dialog is already open, and `close()` on a
     * closed dialog fires nothing, so both are guarded on the element's own
     * `open` property rather than on the state alone - the two can disagree
     * after the user closes the dialog with Escape.
     */
    private syncAddDialog(): void {
        for (const child of childTypes) {
            const dialog = this.renderRoot.querySelector<HTMLDialogElement>(`dialog[data-dialog-for="${child}"]`);
            if (dialog === null) {
                continue;
            }
            const shouldBeOpen = this.addDialogFor === child;
            if (shouldBeOpen && !dialog.open) {
                dialog.showModal();
            } else if (!shouldBeOpen && dialog.open) {
                dialog.close();
            }
        }
    }

    private openAddDialog(child: ChildType): void {
        this.addDialogFor = child;
    }

    /**
     * Closes the dialog and throws the half typed record away.
     *
     * Reached from four directions, which is why it is one method: the cancel
     * button, the dialog's own `cancel` event (Escape), its `close` event, and
     * `field-cancel` from a field inside it.
     *
     * That last one is the reason this does not rely on the native Escape
     * handling alone. A field calls `preventDefault()` on Escape before
     * emitting `field-cancel`, and whether that suppresses a dialog's close
     * request is not something to depend on - it differs between engines. The
     * surface therefore closes the dialog itself, and the native path becoming
     * a no-op is fine: `close()` on a closed dialog does nothing.
     */
    private closeAddDialog(child: ChildType): void {
        if (this.addDialogFor !== child) {
            return;
        }
        this.addDialogFor = null;
        this.edits = this.edits.endRecord(newChildTarget(child));
        this.pendingFocus = null;
        // Back to the button that opened it, which is where the reader was.
        this.renderRoot.querySelector<HTMLButtonElement>(`button[data-add-for="${child}"]`)?.focus();
    }

    protected override updated(): void {
        this.syncAddDialog();
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
        this.configuration = parseComponentConfiguration(readJson(this.getAttribute('data-config')));
        this.client = new ProfileEndpointClient(endpoints, token);
        this.takeOverFromServerRendering();
        this.profile = profile;
    }

    /**
     * Removes the server rendered view this element wraps.
     *
     * Under a shadow root this needed no code at all: light DOM children are
     * not rendered unless a `<slot>` asks for them, so {@see render} returned
     * one while unenhanced and returned none once it had a profile. That single
     * mechanism did the hiding, and it does not exist in the light DOM.
     *
     * Nothing replaces it implicitly either. `lit-html` **inserts** its parts
     * into the container and does not clear what is already there, so the
     * server rendered profile stays exactly where it was and the reader sees the
     * profile twice - once as static text, once as the editing surface. That is
     * not a subtle regression; it was the first thing the acceptance suite
     * caught.
     *
     * Called from {@see initialize} rather than from a render hook, and only
     * once enhancement is certain. Removing it any earlier would take away the
     * server rendered view from a visitor whose element is about to decide it
     * cannot enhance - which is the one case that view exists for.
     */
    private takeOverFromServerRendering(): void {
        while (this.firstChild !== null) {
            this.removeChild(this.firstChild);
        }
    }

    private renderRecord(profile: ProfileRecord, target: RecordTarget): TemplateResult {
        const edit = this.edits.of(target);

        return html`
            <div class="${classesFor(this.configuration, 'record', 'frontend-edit-record')}">
                <div class="frontend-edit-record-actions">
                    ${this.renderRecordActions(profile, target, edit)}
                    ${target.child === null && profile.hidden
                        ? html`<span class="${classesFor(this.configuration, 'state', 'frontend-edit-state')}">${this.text(stateLabelKey('hidden'))}</span>`
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
                .configuration="${this.configuration}"
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
                <button
                    class="${this.buttonClass('primary')}"
                    type="button"
                    data-variant="primary"
                    ?disabled="${edit.busy}"
                    @click="${(): void => void this.submitRecord(target)}"
                >
                    ${icon(this.configuration, 'apply')}
                    <span class="frontend-edit-button-label">${this.text(actionLabelKey('save'))}</span>
                </button>
                <button type="button" class="${this.buttonClass()}" ?disabled="${edit.busy}" @click="${(): void => this.cancelRecord(target)}">
                    ${icon(this.configuration, 'cancel')}
                    <span class="frontend-edit-button-label">${this.text(actionLabelKey('cancel'))}</span>
                </button>
            `;
        }

        return html`
            <button
                class="${this.buttonClass()}"
                type="button"
                ?disabled="${edit?.busy ?? false}"
                @click="${(): void => this.beginRecord(profile, target)}"
            >
                ${icon(this.configuration, 'editRecord')}
                <span class="frontend-edit-button-label">${this.text(actionLabelKey('editRecord'))}</span>
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
                .configuration="${this.configuration}"
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
            <section class="frontend-edit-children">
                <h3>${this.text(sectionLabelKey(child))}</h3>
                <ol class="frontend-edit-children-list">
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
            <li class="${classesFor(this.configuration, 'child', 'frontend-edit-child')}">
                <header class="frontend-edit-child-header">
                    ${this.renderChildTitle(child, record)}
                    ${hidden ? html`<span class="${classesFor(this.configuration, 'state', 'frontend-edit-state')}">${this.text(stateLabelKey('hidden'))}</span>` : nothing}
                <div class="frontend-edit-child-actions">
                    ${index === 0 ? nothing : html`
                        <button
                            class="${this.buttonClass(null, true)}"
                            type="button"
                            data-icon-only
                            title="${this.text(actionLabelKey('moveToTop'))}"
                            ?disabled="${busy}"
                            @click="${(): void => void this.moveChildToEnd(child, record.uid, 'top')}"
                        >
                            ${icon(this.configuration, 'moveToTop')}
                            <span class="frontend-edit-button-label">${this.text(actionLabelKey('moveToTop'))}</span>
                        </button>
                    `}
                    ${index === 0 ? nothing : html`
                        <button
                            class="${this.buttonClass(null, true)}"
                            type="button"
                            data-icon-only
                            title="${this.text(actionLabelKey('moveUp'))}"
                            ?disabled="${busy}"
                            @click="${(): void => void this.moveChild(child, record.uid, -1)}"
                        >
                            ${icon(this.configuration, 'moveUp')}
                            <span class="frontend-edit-button-label">${this.text(actionLabelKey('moveUp'))}</span>
                        </button>
                    `}
                    ${index === total - 1 ? nothing : html`
                        <button
                            class="${this.buttonClass(null, true)}"
                            type="button"
                            data-icon-only
                            title="${this.text(actionLabelKey('moveDown'))}"
                            ?disabled="${busy}"
                            @click="${(): void => void this.moveChild(child, record.uid, 1)}"
                        >
                            ${icon(this.configuration, 'moveDown')}
                            <span class="frontend-edit-button-label">${this.text(actionLabelKey('moveDown'))}</span>
                        </button>
                    `}
                    ${index === total - 1 ? nothing : html`
                        <button
                            class="${this.buttonClass(null, true)}"
                            type="button"
                            data-icon-only
                            title="${this.text(actionLabelKey('moveToBottom'))}"
                            ?disabled="${busy}"
                            @click="${(): void => void this.moveChildToEnd(child, record.uid, 'bottom')}"
                        >
                            ${icon(this.configuration, 'moveToBottom')}
                            <span class="frontend-edit-button-label">${this.text(actionLabelKey('moveToBottom'))}</span>
                        </button>
                    `}
                    <button
                        class="${this.buttonClass(null, true)}"
                        type="button"
                        data-icon-only
                        title="${this.text(actionLabelKey(hidden ? 'show' : 'hide'))}"
                        ?disabled="${busy}"
                        @click="${(): void => void this.setChildVisibility(child, record.uid, !hidden)}"
                    >
                        ${icon(this.configuration, hidden ? 'show' : 'hide')}
                        <span class="frontend-edit-button-label">${this.text(actionLabelKey(hidden ? 'show' : 'hide'))}</span>
                    </button>
                    <button
                        class="${this.buttonClass('danger', true)}"
                        type="button"
                        data-icon-only
                        data-variant="danger"
                        title="${this.text(actionLabelKey('remove'))}"
                        ?disabled="${busy}"
                        @click="${(): void => void this.deleteChild(child, record.uid)}"
                    >
                        ${icon(this.configuration, 'remove')}
                        <span class="frontend-edit-button-label">${this.text(actionLabelKey('remove'))}</span>
                    </button>
                </div>
                </header>
                ${this.renderRecord(profile, target)}
            </li>
        `;
    }

    /**
     * The heading of one child record.
     *
     * Its content is the record's own — the translated type, and the value that
     * tells two records of the same type apart. Never its position: the surface
     * reorders these records, and a number would rename every row below the one
     * that was just moved.
     *
     * Both halves may be empty, and the separator is drawn only when both are
     * present, so an incomplete record produces a shorter heading rather than a
     * stray middle dot. When neither is there, the heading is skipped entirely —
     * the toolbar keeps the row, and an empty element with a border would say
     * that something is missing rather than that nothing was entered.
     */
    private renderChildTitle(child: ChildType, record: ChildRecord): TemplateResult | typeof nothing {
        const identity = childIdentity(child, record);
        const type = identity.type === ''
            ? ''
            : this.text(choiceLabelKey(child, 'type', identity.type));
        const parts = [type, identity.detail].filter((part: string): boolean => part !== '');
        if (parts.length === 0) {
            return nothing;
        }

        return html`<span class="frontend-edit-child-title">${parts.join(' · ')}</span>`;
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
    /**
     * The button that opens the add dialog, and the dialog itself.
     *
     * The dialog is always in the document and is opened with `showModal()`
     * from {@see updated}, never by rendering the `open` attribute. Only
     * `showModal()` promotes the element into the top layer and brings the
     * focus trap, the inert background and the backdrop with it; `open` alone
     * renders a non-modal box that the page scrolls behind and the focus walks
     * straight out of.
     */
    private renderNewChild(child: ChildType): TemplateResult {
        const target = newChildTarget(child);
        const defaults = initialValues(fieldsOfChild(child));
        const edit = this.edits.of(target);

        return html`
            <div class="frontend-edit-child-actions">
                <button
                    class="${this.buttonClass('primary')}"
                    type="button"
                    data-variant="primary"
                    data-add-for="${child}"
                    ?disabled="${edit?.busy ?? false}"
                    @click="${(): void => this.openAddDialog(child)}"
                >
                    ${icon(this.configuration, 'add')}
                    <span class="frontend-edit-button-label">${this.text(actionLabelKey('add'))}</span>
                </button>
            </div>
            <dialog
                class="frontend-edit-dialog"
                data-dialog-for="${child}"
                aria-labelledby="${this.uid}-dialog-${child}"
                @cancel="${(): void => this.closeAddDialog(child)}"
                @close="${(): void => this.closeAddDialog(child)}"
            >
                <h4 class="frontend-edit-dialog-title" id="${this.uid}-dialog-${child}">
                    ${this.text(dialogTitleLabelKey(child))}
                </h4>
                <div class="${classesFor(this.configuration, 'child', 'frontend-edit-child', 'frontend-edit-child-new')}">
                ${this.renderGeneralErrors(target)}
                ${fieldsOfChild(child).map((definition: FieldDefinition): TemplateResult => {
                    const field = definition.name;

                    return html`
                        <modern-extbase-frontend-edit-field
                            data-focus="${focusKey(target, field)}"
                            .definition="${definition}"
                            .scope="${child}"
                            .labels="${this.labels}"
                            .configuration="${this.configuration}"
                            .serverValue="${''}"
                            .draftValue="${this.edits.draftOf(target, field, defaults[field] ?? '')}"
                            .editing="${true}"
                            .busy="${edit?.busy ?? false}"
                            .recordMode="${true}"
                            .errors="${this.edits.errorsOf(target, field)}"
                            @field-input="${(event: CustomEvent<{ value: string }>): void =>
                                this.onInput(target, field, event.detail.value)}"
                            @field-apply="${(): void => void this.addChild(child)}"
                            @field-cancel="${(): void => this.closeAddDialog(child)}"
                        ></modern-extbase-frontend-edit-field>
                    `;
                })}
                </div>
                <div class="frontend-edit-dialog-actions">
                    <button
                        class="${this.buttonClass('primary')}"
                        type="button"
                        data-variant="primary"
                        ?disabled="${edit?.busy ?? false}"
                        @click="${(): void => void this.addChild(child)}"
                    >
                        ${icon(this.configuration, 'add')}
                        <span class="frontend-edit-button-label">${this.text(actionLabelKey('add'))}</span>
                    </button>
                    <button
                        class="${this.buttonClass()}"
                        type="button"
                        ?disabled="${edit?.busy ?? false}"
                        @click="${(): void => this.closeAddDialog(child)}"
                    >
                        ${icon(this.configuration, 'cancel')}
                        <span class="frontend-edit-button-label">${this.text(actionLabelKey('cancel'))}</span>
                    </button>
                </div>
            </dialog>
        `;
    }

    private renderGeneralErrors(target: RecordTarget): TemplateResult | typeof nothing {
        const messages = this.edits.generalErrorsOf(target);
        if (messages.length === 0) {
            return nothing;
        }

        return html`
            <ul class="${classesFor(this.configuration, 'errors', 'frontend-edit-errors')}" role="alert">
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
                // record starts from, and closes the dialog: the record it was
                // collecting now exists in the list behind it.
                this.edits = this.edits.endRecord(target);
                this.addDialogFor = null;
                this.renderRoot.querySelector<HTMLButtonElement>(`button[data-add-for="${child}"]`)?.focus();
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

    /**
     * Sends a child to the top or the bottom of its collection.
     *
     * Goes through the same `reorderChildren` endpoint as a single step move,
     * and that is worth stating because it looks like it should need a new one:
     * the endpoint takes a **complete permutation** of the collection rather
     * than a delta, so "one position up" and "all the way to the top" are the
     * same request with a different list. Nothing was added on the server for
     * this feature.
     */
    private async moveChildToEnd(child: ChildType, childUid: number, end: CollectionEnd): Promise<void> {
        const profile = this.profile;
        if (profile === null) {
            return;
        }
        const order = childOrderMovedToEnd(profile, child, childUid, end);
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
    /**
     * The class attribute of a button, including whatever the installation
     * configured for its kind.
     *
     * `data-variant` still decides the *emphasis* and stays an attribute: it is
     * this extension's own presentational state and the acceptance suite reads
     * it. The classes here are the seam a project styles through, and they are
     * additive - the configured value cannot remove anything the surface needs.
     */
    private buttonClass(variant: 'primary' | 'danger' | null = null, iconOnly = false): string {
        const kinds: ElementType[] = ['button'];
        if (variant === 'primary') {
            kinds.push('buttonPrimary');
        }
        if (variant === 'danger') {
            kinds.push('buttonDanger');
        }
        if (iconOnly) {
            kinds.push('buttonIconOnly');
        }

        return kinds
            .map((kind: ElementType): string => classesFor(this.configuration, kind))
            .filter((entry: string): boolean => entry !== '')
            .join(' ');
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
