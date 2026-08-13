/**
 * The profile image: what is stored, and the two things that may be done to it.
 *
 * Dumb in exactly the way {@see ./editField.js} is dumb — it holds no state,
 * knows neither the endpoints nor the profile, and reports intent rather than
 * outcome. The record element above it owns the requests and the truth.
 *
 * | Event          | Meaning                                              |
 * |----------------|------------------------------------------------------|
 * | `image-select` | a file was picked, `detail.file` carries it         |
 * | `image-remove` | remove the stored image                             |
 *
 * ## Why there is no draft, no apply and no cancel
 *
 * A text field has three states — stored, being typed, submitted — and the
 * middle one is what `Apply` and `Cancel` exist for. A file has two: there is
 * nothing to look at between picking a file and having uploaded it, because the
 * thing the user wants to see is the stored image and only the server can
 * produce it. Picking a file therefore *is* the write. Escape is consequently
 * not bound: it cancels a draft, and there is none. The file input is natively
 * keyboard operable, and `Remove` is an ordinary button, so the keyboard
 * behaviour is the platform's.
 *
 * ## The input is cleared the moment the file is read
 *
 * Two independent reasons, and both are defects when it is not done:
 *
 * 1. **A `change` event only fires when the value changes.** After a rejected
 *    upload the obvious thing for a user to do is to pick the *same* file again
 *    — after fixing it on disk, or simply to retry. With the filename still in
 *    the control that selection is not a change and no event is dispatched: the
 *    surface would sit there, silently, doing nothing.
 * 2. **Nothing is moved into storage on a validation failure.** A control still
 *    showing `holiday.jpg` states the opposite — that the file is held and one
 *    correction away from being saved — while the server has already forgotten
 *    it. The empty control and the notice below it say the same true thing
 *    twice.
 */
import { html, LitElement, nothing } from 'lit';
import type { TemplateResult } from 'lit';
import { customElement, property, query } from 'lit/decorators.js';
import type { ComponentConfiguration, ElementType } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/componentConfiguration.js';
import { classesFor, emptyConfiguration, icon } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/componentConfiguration.js';
import type { ProfileImageRecord } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/types.js';
import type { LabelMap } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js';
import { actionLabelKey, fieldLabelKey, label } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js';
import { imageAccept, imageAlternative, imageField, isDisplayable, uploadFailureMessages } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/imageEdit.js';

/**
 * Unique per document, for the same reason the field element needs one: in the
 * light DOM `id="errors"` is not scoped to anything.
 */
let instances = 0;

@customElement('modern-extbase-frontend-edit-image')
export class EditImageElement extends LitElement {
    /**
     * Renders into the light DOM, for the reason {@see ./editField.js} gives.
     *
     * The figure, the image and the caption this element draws are styled from
     * `Build/Sources/Css/frontend/frontend-edit.css` now. They are the only
     * rules of the surface that are not shared with another element, and they
     * moved with everything else rather than being kept here as the one
     * exception - a component that carried some of its own CSS and not the rest
     * would be worse than either arrangement.
     */
    protected override createRenderRoot(): HTMLElement {
        return this;
    }

    /** Unique across the document. See the counter in `editField.ts`. */
    private readonly uid = `frontend-edit-image-${++instances}`;

    /**
     * The stored image, or `null` for a profile that has none — a state, not an
     * error.
     */
    @property({ attribute: false })
    public image: ProfileImageRecord | null = null;

    @property({ attribute: false })
    public labels: LabelMap = {};

    /**
     * The icon markup and the additional CSS classes, resolved by the server.
     *
     * A property rather than an attribute read here: the record element owns it
     * and hands the same object to every child, which is what stops two
     * elements of one surface from disagreeing about it.
     */
    @property({ attribute: false })
    public configuration: ComponentConfiguration = emptyConfiguration;

    /**
     * The profile name, for the alternative text fallback. It comes from the
     * document rather than from the page, so it follows a saved name change.
     */
    @property({ type: String })
    public profileName = '';

    @property({ type: Boolean, reflect: true })
    public busy = false;

    /**
     * The `422` messages the server sent for the image property.
     */
    @property({ attribute: false })
    public errors: readonly string[] = [];

    /**
     * Whether the last upload failed, for any reason at all — a rejected file, a
     * `403`, a request that never arrived. All of them leave the file unstored,
     * so all of them have to say that the file has to be picked again.
     */
    @property({ type: Boolean })
    public rejected = false;

    @query('.frontend-edit-field-control')
    private readonly control!: HTMLInputElement | null;

    public override render(): TemplateResult {
        const messages = uploadFailureMessages(this.errors, this.rejected ? this.text('error.imageNotStored') : '');

        return html`
            <div class="frontend-edit-field">
                <span class="${classesFor(this.configuration, 'label', 'frontend-edit-field-label')}" id="${this.uid}-label">${this.text(fieldLabelKey('profile', imageField))}</span>
                <div class="frontend-edit-field-body">
                    ${this.renderImage()}
                    <span class="frontend-edit-field-actions">
                        <label class="${classesFor(this.configuration, 'filePicker', 'frontend-edit-file-picker')}" ?data-disabled="${this.busy}">
                            <input
                                class="${classesFor(this.configuration, 'control', 'frontend-edit-field-control', 'frontend-edit-visually-hidden')}"
                                type="file"
                                accept="${imageAccept}"
                                aria-labelledby="${this.uid}-label"
                                aria-invalid="${messages.length > 0 ? 'true' : 'false'}"
                                aria-describedby="${messages.length > 0 ? `${this.uid}-errors` : nothing}"
                                ?disabled="${this.busy}"
                                @change="${this.onSelect}"
                            />
                            ${icon(this.configuration, 'chooseImage')}
                            <span class="frontend-edit-button-label">
                                ${this.text(actionLabelKey(this.image === null ? 'chooseImage' : 'replaceImage'))}
                            </span>
                        </label>
                        <button
                            class="${this.buttonClass('danger', true)}"
                            type="button"
                            data-variant="danger"
                            data-icon-only
                            title="${this.text(actionLabelKey('remove'))}"
                            aria-describedby="${this.uid}-label"
                            ?disabled="${this.busy || this.image === null}"
                            @click="${this.onRemove}"
                        >
                            ${icon(this.configuration, 'remove')}
                            <span class="frontend-edit-button-label">${this.text(actionLabelKey('remove'))}</span>
                        </button>
                    </span>
                </div>
                ${messages.length > 0 ? this.renderErrors(messages) : nothing}
            </div>
        `;
    }

    /**
     * Moves the focus into the file input.
     *
     * Same contract as `EditFieldElement.focusControl()`, so that the record
     * element's single focus mechanism reaches this element too — which is what
     * puts the focus here after a `422` that named the image.
     */
    public focusControl(): void {
        this.control?.focus();
    }

    /**
     * The stored image, exactly as the server describes it.
     *
     * The URL is never assembled here. The upload API appends a random suffix to
     * the client filename, so what a file is called after it has been stored is
     * not derivable from what it was called before — a guessed URL is wrong the
     * first time it is used.
     */
    private renderImage(): TemplateResult {
        const image = this.image;
        if (!isDisplayable(image)) {
            // Also the case for a reference whose file is gone: there is nothing
            // to show, and `Remove` stays enabled so it can be cleared.
            return html`<span class="frontend-edit-field-value is-empty"></span>`;
        }

        return html`
            <figure class="frontend-edit-field-value">
                <img
                    src="${image.publicUrl}"
                    alt="${imageAlternative(image, this.text('profile.image.alt'), this.profileName)}"
                    width="${image.width ?? nothing}"
                    height="${image.height ?? nothing}"
                    loading="lazy"
                />
                ${image.title === '' ? nothing : html`<figcaption>${image.title}</figcaption>`}
            </figure>
        `;
    }

    private renderErrors(messages: readonly string[]): TemplateResult {
        return html`
            <ul class="${classesFor(this.configuration, 'errors', 'frontend-edit-field-errors')}" id="${this.uid}-errors" role="alert">
                ${messages.map((message: string): TemplateResult => html`<li>${message}</li>`)}
            </ul>
        `;
    }

    private onSelect(event: Event): void {
        const control = event.target as HTMLInputElement;
        const file = control.files?.item(0) ?? null;
        // Cleared before the event is emitted, not after: the handler above
        // starts a request, and the control must already be empty by the time
        // anything can look at it. See the class docblock for both reasons.
        control.value = '';
        if (file === null) {
            return;
        }
        this.dispatchEvent(new CustomEvent('image-select', {
            detail: { file },
            bubbles: true,
            composed: true,
        }));
    }

    private onRemove(): void {
        this.dispatchEvent(new CustomEvent('image-remove', { bubbles: true, composed: true }));
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

declare global {
    interface HTMLElementTagNameMap {
        'modern-extbase-frontend-edit-image': EditImageElement;
    }
}
