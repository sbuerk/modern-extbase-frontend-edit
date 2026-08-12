/* Generated from Build/Sources/TypeScript — do not edit. */
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __decorateClass = (decorators, target, key, kind) => {
  var result = kind > 1 ? void 0 : kind ? __getOwnPropDesc(target, key) : target;
  for (var i = decorators.length - 1, decorator; i >= 0; i--)
    if (decorator = decorators[i])
      result = (kind ? decorator(target, key, result) : decorator(result)) || result;
  if (kind && result) __defProp(target, key, result);
  return result;
};
import { css, html, LitElement, nothing } from "lit";
import { customElement, property, query } from "lit/decorators.js";
import { icon } from "@sbuerk/modern-extbase-frontend-edit/frontend/icon/icons.js";
import { controls } from "@sbuerk/modern-extbase-frontend-edit/frontend/style/controls.js";
import { field } from "@sbuerk/modern-extbase-frontend-edit/frontend/style/field.js";
import { actionLabelKey, fieldLabelKey, label } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js";
import { imageAccept, imageAlternative, imageField, isDisplayable, uploadFailureMessages } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/imageEdit.js";
let EditImageElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.image = null;
    this.labels = {};
    this.profileName = "";
    this.busy = false;
    this.errors = [];
    this.rejected = false;
  }
  render() {
    const messages = uploadFailureMessages(this.errors, this.rejected ? this.text("error.imageNotStored") : "");
    return html`
            <div class="field">
                <span class="field-label" id="label">${this.text(fieldLabelKey("profile", imageField))}</span>
                <div class="field-body">
                    ${this.renderImage()}
                    <span class="field-actions">
                        <label class="file-picker" ?data-disabled="${this.busy}">
                            <input
                                class="field-control visually-hidden"
                                type="file"
                                accept="${imageAccept}"
                                aria-labelledby="label"
                                aria-invalid="${messages.length > 0 ? "true" : "false"}"
                                aria-describedby="${messages.length > 0 ? "errors" : nothing}"
                                ?disabled="${this.busy}"
                                @change="${this.onSelect}"
                            />
                            ${icon("chooseImage")}
                            <span class="button-label">
                                ${this.text(actionLabelKey(this.image === null ? "chooseImage" : "replaceImage"))}
                            </span>
                        </label>
                        <button
                            type="button"
                            data-variant="danger"
                            aria-describedby="label"
                            ?disabled="${this.busy || this.image === null}"
                            @click="${this.onRemove}"
                        >
                            ${icon("remove")}
                            <span class="button-label">${this.text(actionLabelKey("remove"))}</span>
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
  focusControl() {
    var _a;
    (_a = this.control) == null ? void 0 : _a.focus();
  }
  /**
   * The stored image, exactly as the server describes it.
   *
   * The URL is never assembled here. The upload API appends a random suffix to
   * the client filename, so what a file is called after it has been stored is
   * not derivable from what it was called before — a guessed URL is wrong the
   * first time it is used.
   */
  renderImage() {
    const image = this.image;
    if (!isDisplayable(image)) {
      return html`<span class="field-value is-empty"></span>`;
    }
    return html`
            <figure class="field-value">
                <img
                    src="${image.publicUrl}"
                    alt="${imageAlternative(image, this.text("profile.image.alt"), this.profileName)}"
                    width="${image.width ?? nothing}"
                    height="${image.height ?? nothing}"
                    loading="lazy"
                />
                ${image.title === "" ? nothing : html`<figcaption>${image.title}</figcaption>`}
            </figure>
        `;
  }
  renderErrors(messages) {
    return html`
            <ul class="field-errors" id="errors" role="alert">
                ${messages.map((message) => html`<li>${message}</li>`)}
            </ul>
        `;
  }
  onSelect(event) {
    var _a;
    const control = event.target;
    const file = ((_a = control.files) == null ? void 0 : _a.item(0)) ?? null;
    control.value = "";
    if (file === null) {
      return;
    }
    this.dispatchEvent(new CustomEvent("image-select", {
      detail: { file },
      bubbles: true,
      composed: true
    }));
  }
  onRemove() {
    this.dispatchEvent(new CustomEvent("image-remove", { bubbles: true, composed: true }));
  }
  text(key) {
    return label(this.labels, key);
  }
};
EditImageElement.styles = [
  controls,
  field,
  css`
            :host {
                display: block;
            }

            figure {
                margin: 0;
            }

            /*
             * The stored dimensions are written as attributes so the layout does
             * not jump while the image loads, and bounded here because a
             * portrait straight from a camera is wider than the surface.
             *
             * The frame is what separates a stored image from one the page
             * happens to contain: it is the same border the controls beside it
             * carry, so the image reads as part of the editing surface rather
             * than as content inside it.
             */
            img {
                display: block;
                max-width: 12rem;
                height: auto;
                border: var(--frontend-edit-border-width) solid var(--frontend-edit-color-border);
                border-radius: var(--frontend-edit-radius-lg);
                background-color: var(--frontend-edit-color-surface-sunken);
            }

            figcaption {
                margin-top: var(--frontend-edit-space-xs);
                font-size: var(--frontend-edit-font-size-sm);
                color: var(--frontend-edit-color-muted);
            }
        `
];
__decorateClass([
  property({ attribute: false })
], EditImageElement.prototype, "image", 2);
__decorateClass([
  property({ attribute: false })
], EditImageElement.prototype, "labels", 2);
__decorateClass([
  property({ type: String })
], EditImageElement.prototype, "profileName", 2);
__decorateClass([
  property({ type: Boolean, reflect: true })
], EditImageElement.prototype, "busy", 2);
__decorateClass([
  property({ attribute: false })
], EditImageElement.prototype, "errors", 2);
__decorateClass([
  property({ type: Boolean })
], EditImageElement.prototype, "rejected", 2);
__decorateClass([
  query(".field-control")
], EditImageElement.prototype, "control", 2);
EditImageElement = __decorateClass([
  customElement("modern-extbase-frontend-edit-image")
], EditImageElement);
export {
  EditImageElement
};
