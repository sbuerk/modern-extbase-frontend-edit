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
import { css, html, LitElement, nothing } from 'lit';
import type { TemplateResult } from 'lit';
import { customElement, property, query } from 'lit/decorators.js';
import type { ProfileImageRecord } from '../model/types.js';
import type { LabelMap } from '../model/labels.js';
import { actionLabelKey, fieldLabelKey, label } from '../model/labels.js';
import { imageAccept, imageAlternative, imageField, isDisplayable, uploadFailureMessages } from '../model/imageEdit.js';

@customElement('modern-extbase-frontend-edit-image')
export class EditImageElement extends LitElement {
    public static override readonly styles = css`
        :host {
            display: block;
        }

        .field {
            display: grid;
            gap: 0.25rem;
            padding: 0.25rem 0;
        }

        .field-label {
            font-weight: 600;
        }

        .field-body {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .field-value {
            flex: 1 1 12rem;
            min-width: 0;
        }

        .field-value.is-empty::after {
            content: '—';
        }

        figure {
            margin: 0;
        }

        /*
         * The stored dimensions are written as attributes so the layout does not
         * jump while the image loads, and bounded here because a portrait
         * straight from a camera is wider than the surface.
         */
        img {
            display: block;
            max-width: 12rem;
            height: auto;
        }

        figcaption {
            font-size: 0.875em;
        }

        input {
            font: inherit;
        }

        button {
            font: inherit;
        }

        .field-errors {
            margin: 0;
            padding: 0;
            list-style: none;
            color: #a4141a;
        }

        [aria-invalid='true'] {
            outline: 2px solid #a4141a;
        }

        :host([busy]) {
            opacity: 0.6;
        }
    `;

    /**
     * The stored image, or `null` for a profile that has none — a state, not an
     * error.
     */
    @property({ attribute: false })
    public image: ProfileImageRecord | null = null;

    @property({ attribute: false })
    public labels: LabelMap = {};

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

    @query('.field-control')
    private readonly control!: HTMLInputElement | null;

    public override render(): TemplateResult {
        const messages = uploadFailureMessages(this.errors, this.rejected ? this.text('error.imageNotStored') : '');

        return html`
            <div class="field">
                <span class="field-label" id="label">${this.text(fieldLabelKey('profile', imageField))}</span>
                <div class="field-body">
                    ${this.renderImage()}
                    <span class="field-actions">
                        <input
                            class="field-control"
                            type="file"
                            accept="${imageAccept}"
                            aria-labelledby="label"
                            aria-invalid="${messages.length > 0 ? 'true' : 'false'}"
                            aria-describedby="${messages.length > 0 ? 'errors' : nothing}"
                            ?disabled="${this.busy}"
                            @change="${this.onSelect}"
                        />
                        <button
                            type="button"
                            aria-describedby="label"
                            ?disabled="${this.busy || this.image === null}"
                            @click="${this.onRemove}"
                        >
                            ${this.text(actionLabelKey('remove'))}
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
            return html`<span class="field-value is-empty"></span>`;
        }

        return html`
            <figure class="field-value">
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
            <ul class="field-errors" id="errors" role="alert">
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
}

declare global {
    interface HTMLElementTagNameMap {
        'modern-extbase-frontend-edit-image': EditImageElement;
    }
}
