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
import { controls } from "@sbuerk/modern-extbase-frontend-edit/frontend/style/controls.js";
import { field } from "@sbuerk/modern-extbase-frontend-edit/frontend/style/field.js";
import { actionLabelKey, choiceLabelKey, fieldLabelKey, label } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js";
let EditFieldElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.definition = { name: "", control: "line" };
    this.scope = "profile";
    this.labels = {};
    this.serverValue = "";
    this.draftValue = "";
    this.editing = false;
    this.busy = false;
    this.recordMode = false;
    this.errors = [];
  }
  render() {
    const hasErrors = this.errors.length > 0;
    return html`
            <div class="field">
                <span class="field-label" id="label">${label(this.labels, fieldLabelKey(this.scope, this.definition.name))}</span>
                <div class="field-body">
                    ${this.editing ? this.renderControl(hasErrors) : this.renderValue()}
                    ${this.renderActions()}
                </div>
                ${hasErrors ? this.renderErrors() : nothing}
            </div>
        `;
  }
  /**
   * Moves the focus into the control, if there is one.
   *
   * Focus is driven from the record element rather than from here, because
   * only it knows which of several fields a whole record edit should start
   * on, and because it is also what puts the focus back on the field the
   * server complained about after a rejected save.
   */
  focusControl() {
    var _a;
    (_a = this.control) == null ? void 0 : _a.focus();
  }
  /**
   * Keeps a select in sync with its draft.
   *
   * The other controls take their value through a `.value` property binding,
   * which a `<select>` cannot use: the binding is committed before the
   * `<option>` children exist, so the assignment finds nothing to select. The
   * `selected` attribute on the options covers the first render and stops
   * working as soon as the user has touched the control, which makes the
   * initial value the only thing it can be trusted for.
   */
  updated() {
    if (!this.editing || this.definition.control !== "choice") {
      return;
    }
    const control = this.control;
    if (control instanceof HTMLSelectElement && control.value !== this.draftValue) {
      control.value = this.draftValue;
    }
  }
  renderValue() {
    const value = this.displayValue();
    return html`<span class="field-value ${value === "" ? "is-empty" : ""}">${value}</span>`;
  }
  /**
   * The stored value as it is shown when the field is not being edited.
   *
   * A select shows the label of its value rather than the value itself, the
   * same lookup the read only partials do through the database label file. A
   * date shows the stored `Y-m-d` string: the read view formats it with the
   * installation wide setting, which is an integrator decision this element
   * has no access to, and inventing a second format here would make the two
   * views disagree about what is stored.
   */
  displayValue() {
    if (this.definition.control !== "choice" || this.serverValue === "") {
      return this.serverValue;
    }
    return label(this.labels, choiceLabelKey(this.scope, this.definition.name, this.serverValue));
  }
  renderControl(hasErrors) {
    const shared = {
      invalid: hasErrors ? "true" : "false",
      describedBy: hasErrors ? "errors" : void 0
    };
    if (this.definition.control === "choice") {
      return html`
                <select
                    class="field-control"
                    aria-labelledby="label"
                    aria-invalid="${shared.invalid}"
                    aria-describedby="${shared.describedBy ?? nothing}"
                    ?disabled="${this.busy}"
                    @change="${this.onControlInput}"
                    @keydown="${this.onKeyDown}"
                >
                    ${(this.definition.choices ?? []).map((choice) => html`
                        <option value="${choice}" ?selected="${choice === this.draftValue}">
                            ${label(this.labels, choiceLabelKey(this.scope, this.definition.name, choice))}
                        </option>
                    `)}
                </select>
            `;
    }
    if (this.definition.control === "text") {
      return html`
                <textarea
                    class="field-control"
                    aria-labelledby="label"
                    aria-invalid="${shared.invalid}"
                    aria-describedby="${shared.describedBy ?? nothing}"
                    maxlength="${this.definition.maxLength ?? nothing}"
                    ?disabled="${this.busy}"
                    .value="${this.draftValue}"
                    @input="${this.onControlInput}"
                    @keydown="${this.onKeyDown}"
                ></textarea>
            `;
    }
    return html`
            <input
                class="field-control"
                type="${this.definition.control === "date" ? "date" : "text"}"
                aria-labelledby="label"
                aria-invalid="${shared.invalid}"
                aria-describedby="${shared.describedBy ?? nothing}"
                maxlength="${this.definition.maxLength ?? nothing}"
                ?disabled="${this.busy}"
                .value="${this.draftValue}"
                @input="${this.onControlInput}"
                @keydown="${this.onKeyDown}"
            />
        `;
  }
  /**
   * The per field affordances.
   *
   * None of them in a whole record edit: the record's own submit and cancel
   * are the only ones there, and a second pair meaning something narrower
   * next to them is how a user ends up saving one field while believing they
   * saved five.
   */
  renderActions() {
    if (this.recordMode) {
      return nothing;
    }
    if (!this.editing) {
      return html`
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onEdit}">
                    ${label(this.labels, actionLabelKey("edit"))}
                </button>
            `;
    }
    return html`
            <span class="field-actions">
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onApply}">
                    ${label(this.labels, actionLabelKey("apply"))}
                </button>
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onCancel}">
                    ${label(this.labels, actionLabelKey("cancel"))}
                </button>
            </span>
        `;
  }
  renderErrors() {
    return html`
            <ul class="field-errors" id="errors" role="alert">
                ${this.errors.map((message) => html`<li>${message}</li>`)}
            </ul>
        `;
  }
  onControlInput(event) {
    const control = event.target;
    this.emit("field-input", { value: control.value });
  }
  /**
   * Enter applies, Escape cancels.
   *
   * Enter is not bound in a textarea, where it is a newline and where taking
   * it away would make a biography a single line. Escape is bound everywhere,
   * because a control a user cannot get out of with the keyboard is a trap.
   */
  onKeyDown(event) {
    if (event.key === "Escape") {
      event.preventDefault();
      this.emit("field-cancel");
      return;
    }
    if (event.key === "Enter" && this.definition.control !== "text") {
      event.preventDefault();
      this.emit("field-apply");
    }
  }
  onEdit() {
    this.emit("field-edit");
  }
  onApply() {
    this.emit("field-apply");
  }
  onCancel() {
    this.emit("field-cancel");
  }
  emit(type, detail = {}) {
    this.dispatchEvent(
      new CustomEvent(type, {
        detail: { field: this.definition.name, ...detail },
        bubbles: true,
        composed: true
      })
    );
  }
};
/*
 * Everything this element draws is the shared field chrome, so its own block
 * is one declaration: the custom element is inline by default and has to be
 * told otherwise before any of the layout below it applies.
 */
EditFieldElement.styles = [
  controls,
  field,
  css`
            :host {
                display: block;
            }
        `
];
__decorateClass([
  property({ attribute: false })
], EditFieldElement.prototype, "definition", 2);
__decorateClass([
  property({ type: String })
], EditFieldElement.prototype, "scope", 2);
__decorateClass([
  property({ attribute: false })
], EditFieldElement.prototype, "labels", 2);
__decorateClass([
  property({ type: String })
], EditFieldElement.prototype, "serverValue", 2);
__decorateClass([
  property({ type: String })
], EditFieldElement.prototype, "draftValue", 2);
__decorateClass([
  property({ type: Boolean, reflect: true })
], EditFieldElement.prototype, "editing", 2);
__decorateClass([
  property({ type: Boolean, reflect: true })
], EditFieldElement.prototype, "busy", 2);
__decorateClass([
  property({ type: Boolean })
], EditFieldElement.prototype, "recordMode", 2);
__decorateClass([
  property({ attribute: false })
], EditFieldElement.prototype, "errors", 2);
__decorateClass([
  query(".field-control")
], EditFieldElement.prototype, "control", 2);
EditFieldElement = __decorateClass([
  customElement("modern-extbase-frontend-edit-field")
], EditFieldElement);
export {
  EditFieldElement
};
