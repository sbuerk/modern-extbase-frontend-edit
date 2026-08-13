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
import { html, LitElement, nothing } from "lit";
import { customElement, property, query } from "lit/decorators.js";
import { classesFor, emptyConfiguration, icon } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/componentConfiguration.js";
import { actionLabelKey, fieldLabelKey, label } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js";
import { imageAccept, imageAlternative, imageField, isDisplayable, uploadFailureMessages } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/imageEdit.js";
let instances = 0;
let EditImageElement = class extends LitElement {
  constructor() {
    super(...arguments);
    /** Unique across the document. See the counter in `editField.ts`. */
    this.uid = `frontend-edit-image-${++instances}`;
    this.image = null;
    this.labels = {};
    this.configuration = emptyConfiguration;
    this.profileName = "";
    this.busy = false;
    this.errors = [];
    this.rejected = false;
  }
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
  createRenderRoot() {
    return this;
  }
  render() {
    const messages = uploadFailureMessages(this.errors, this.rejected ? this.text("error.imageNotStored") : "");
    return html`
            <div class="frontend-edit-field">
                <span class="${classesFor(this.configuration, "label", "frontend-edit-field-label")}" id="${this.uid}-label">${this.text(fieldLabelKey("profile", imageField))}</span>
                <div class="frontend-edit-field-body">
                    ${this.renderImage()}
                    <span class="frontend-edit-field-actions">
                        <label class="${classesFor(this.configuration, "filePicker", "frontend-edit-file-picker")}" ?data-disabled="${this.busy}">
                            <input
                                class="${classesFor(this.configuration, "control", "frontend-edit-field-control", "frontend-edit-visually-hidden")}"
                                type="file"
                                accept="${imageAccept}"
                                aria-labelledby="${this.uid}-label"
                                aria-invalid="${messages.length > 0 ? "true" : "false"}"
                                aria-describedby="${messages.length > 0 ? `${this.uid}-errors` : nothing}"
                                ?disabled="${this.busy}"
                                @change="${this.onSelect}"
                            />
                            ${icon(this.configuration, "chooseImage")}
                            <span class="frontend-edit-button-label">
                                ${this.text(actionLabelKey(this.image === null ? "chooseImage" : "replaceImage"))}
                            </span>
                        </label>
                        <button
                            class="${this.buttonClass("danger")}"
                            type="button"
                            data-variant="danger"
                            aria-describedby="${this.uid}-label"
                            ?disabled="${this.busy || this.image === null}"
                            @click="${this.onRemove}"
                        >
                            ${icon(this.configuration, "remove")}
                            <span class="frontend-edit-button-label">${this.text(actionLabelKey("remove"))}</span>
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
      return html`<span class="frontend-edit-field-value is-empty"></span>`;
    }
    return html`
            <figure class="frontend-edit-field-value">
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
            <ul class="${classesFor(this.configuration, "errors", "frontend-edit-field-errors")}" id="${this.uid}-errors" role="alert">
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
  /**
   * The class attribute of a button, including whatever the installation
   * configured for its kind.
   *
   * `data-variant` still decides the *emphasis* and stays an attribute: it is
   * this extension's own presentational state and the acceptance suite reads
   * it. The classes here are the seam a project styles through, and they are
   * additive - the configured value cannot remove anything the surface needs.
   */
  buttonClass(variant = null, iconOnly = false) {
    const kinds = ["button"];
    if (variant === "primary") {
      kinds.push("buttonPrimary");
    }
    if (variant === "danger") {
      kinds.push("buttonDanger");
    }
    if (iconOnly) {
      kinds.push("buttonIconOnly");
    }
    return kinds.map((kind) => classesFor(this.configuration, kind)).filter((entry) => entry !== "").join(" ");
  }
};
__decorateClass([
  property({ attribute: false })
], EditImageElement.prototype, "image", 2);
__decorateClass([
  property({ attribute: false })
], EditImageElement.prototype, "labels", 2);
__decorateClass([
  property({ attribute: false })
], EditImageElement.prototype, "configuration", 2);
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
  query(".frontend-edit-field-control")
], EditImageElement.prototype, "control", 2);
EditImageElement = __decorateClass([
  customElement("modern-extbase-frontend-edit-image")
], EditImageElement);
export {
  EditImageElement
};
