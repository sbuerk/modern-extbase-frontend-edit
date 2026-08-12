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
import { customElement, state } from "lit/decorators.js";
import { repeat } from "lit/directives/repeat.js";
import { childTypes } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/types.js";
import {
  childTarget,
  isNewChildTarget,
  newChildTarget,
  profileTarget,
  targetKey,
  targetScope
} from "@sbuerk/modern-extbase-frontend-edit/frontend/model/recordTarget.js";
import { fieldsOf, fieldsOfChild, initialValues } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/fieldDefinitions.js";
import {
  childrenOf,
  childUids,
  displayName,
  fieldValue,
  isChildHidden,
  movedChildOrder,
  parseProfileRecord,
  recordValues
} from "@sbuerk/modern-extbase-frontend-edit/frontend/model/profileRecord.js";
import { imageField } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/imageEdit.js";
import { EditSessions } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/editState.js";
import { actionLabelKey, label, parseLabels, sectionLabelKey, stateLabelKey } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js";
import { readJson } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/json.js";
import { parseEndpoints } from "@sbuerk/modern-extbase-frontend-edit/frontend/api/endpoints.js";
import {
  addChildPayload,
  fieldPayload,
  imageUploadBody,
  recordPayload,
  removeChildPayload,
  removeImagePayload,
  reorderPayload,
  visibilityPayload
} from "@sbuerk/modern-extbase-frontend-edit/frontend/api/payload.js";
import { ProfileEndpointClient } from "@sbuerk/modern-extbase-frontend-edit/frontend/api/client.js";
import { icon } from "@sbuerk/modern-extbase-frontend-edit/frontend/icon/icons.js";
import { controls } from "@sbuerk/modern-extbase-frontend-edit/frontend/style/controls.js";
import { tokens } from "@sbuerk/modern-extbase-frontend-edit/frontend/style/tokens.js";
import "@sbuerk/modern-extbase-frontend-edit/frontend/component/editField.js";
import "@sbuerk/modern-extbase-frontend-edit/frontend/component/editImage.js";
let ProfileEditElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.profile = null;
    this.edits = EditSessions.empty();
    this.labels = {};
    this.imageRejected = false;
    this.client = null;
    /**
     * The field that takes the focus after the next render, as
     * `<targetKey>|<field>`.
     *
     * Focus cannot be taken during a handler: the control is rendered by the
     * update the handler triggers and does not exist yet when the handler runs.
     */
    this.pendingFocus = null;
  }
  connectedCallback() {
    super.connectedCallback();
    this.initialize();
  }
  render() {
    const profile = this.profile;
    if (profile === null) {
      return html`<slot></slot>`;
    }
    return html`
            ${this.renderRecord(profile, profileTarget)}
            ${childTypes.map((child) => this.renderChildren(profile, child))}
        `;
  }
  updated() {
    const key = this.pendingFocus;
    if (key === null) {
      return;
    }
    this.pendingFocus = null;
    const field = this.renderRoot.querySelector(`[data-focus="${key}"]`);
    if (field === null) {
      return;
    }
    void field.updateComplete.then(() => {
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
  initialize() {
    if (this.client !== null) {
      return;
    }
    const profile = parseProfileRecord(readJson(this.getAttribute("data-profile")));
    const endpoints = parseEndpoints(readJson(this.getAttribute("data-endpoints")));
    const token = this.getAttribute("data-token") ?? "";
    if (profile === null || endpoints === null || token === "") {
      return;
    }
    this.labels = parseLabels(readJson(this.getAttribute("data-labels")));
    this.client = new ProfileEndpointClient(endpoints, token);
    this.profile = profile;
  }
  renderRecord(profile, target) {
    const edit = this.edits.of(target);
    return html`
            <div class="record">
                <div class="record-actions">
                    ${this.renderRecordActions(profile, target, edit)}
                    ${target.child === null && profile.hidden ? html`<span class="state">${this.text(stateLabelKey("hidden"))}</span>` : nothing}
                </div>
                ${this.renderGeneralErrors(target)}
                ${target.child === null ? this.renderImage(profile, target, edit) : nothing}
                ${fieldsOf(target).map((definition) => this.renderField(profile, target, definition, edit))}
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
  renderImage(profile, target, edit) {
    return html`
            <modern-extbase-frontend-edit-image
                data-focus="${focusKey(target, imageField)}"
                .image="${profile.image}"
                .labels="${this.labels}"
                .profileName="${displayName(profile)}"
                .busy="${(edit == null ? void 0 : edit.busy) ?? false}"
                .errors="${this.edits.errorsOf(target, imageField)}"
                .rejected="${this.imageRejected}"
                @image-select="${(event) => void this.uploadImage(event.detail.file)}"
                @image-remove="${() => void this.clearImage()}"
            ></modern-extbase-frontend-edit-image>
        `;
  }
  renderRecordActions(profile, target, edit) {
    if ((edit == null ? void 0 : edit.mode) === "record") {
      return html`
                <button
                    type="button"
                    data-variant="primary"
                    ?disabled="${edit.busy}"
                    @click="${() => void this.submitRecord(target)}"
                >
                    ${icon("apply")}
                    <span class="button-label">${this.text(actionLabelKey("save"))}</span>
                </button>
                <button type="button" ?disabled="${edit.busy}" @click="${() => this.cancelRecord(target)}">
                    ${icon("cancel")}
                    <span class="button-label">${this.text(actionLabelKey("cancel"))}</span>
                </button>
            `;
    }
    return html`
            <button
                type="button"
                ?disabled="${(edit == null ? void 0 : edit.busy) ?? false}"
                @click="${() => this.beginRecord(profile, target)}"
            >
                ${icon("editRecord")}
                <span class="button-label">${this.text(actionLabelKey("editRecord"))}</span>
            </button>
        `;
  }
  renderField(profile, target, definition, edit) {
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
                .editing="${(edit == null ? void 0 : edit.fields.includes(field)) ?? false}"
                .busy="${(edit == null ? void 0 : edit.busy) ?? false}"
                .recordMode="${(edit == null ? void 0 : edit.mode) === "record"}"
                .errors="${this.edits.errorsOf(target, field)}"
                @field-edit="${() => this.beginField(profile, target, field)}"
                @field-input="${(event) => this.onInput(target, field, event.detail.value)}"
                @field-apply="${() => void this.applyField(target, field)}"
                @field-cancel="${() => this.cancelField(target, field)}"
            ></modern-extbase-frontend-edit-field>
        `;
  }
  renderChildren(profile, child) {
    const records = childrenOf(profile, child);
    return html`
            <section class="children">
                <h3>${this.text(sectionLabelKey(child))}</h3>
                <ol class="children-list">
                    ${repeat(
      records,
      (record) => record.uid,
      (record, index) => this.renderChild(profile, child, record, index, records.length)
    )}
                </ol>
                ${this.renderNewChild(child)}
            </section>
        `;
  }
  renderChild(profile, child, record, index, total) {
    const target = childTarget(child, record.uid);
    const busy = this.edits.isBusy(target);
    const hidden = isChildHidden(profile, child, record.uid);
    return html`
            <li class="child">
                ${this.renderRecord(profile, target)}
                <div class="child-actions">
                    <button
                        type="button"
                        data-icon-only
                        ?disabled="${busy || index === 0}"
                        @click="${() => void this.moveChild(child, record.uid, -1)}"
                    >
                        ${icon("moveUp")}
                        <span class="button-label">${this.text(actionLabelKey("moveUp"))}</span>
                    </button>
                    <button
                        type="button"
                        data-icon-only
                        ?disabled="${busy || index === total - 1}"
                        @click="${() => void this.moveChild(child, record.uid, 1)}"
                    >
                        ${icon("moveDown")}
                        <span class="button-label">${this.text(actionLabelKey("moveDown"))}</span>
                    </button>
                    <button
                        type="button"
                        data-icon-only
                        ?disabled="${busy}"
                        @click="${() => void this.setChildVisibility(child, record.uid, !hidden)}"
                    >
                        ${icon(hidden ? "show" : "hide")}
                        <span class="button-label">${this.text(actionLabelKey(hidden ? "show" : "hide"))}</span>
                    </button>
                    <button
                        type="button"
                        data-icon-only
                        data-variant="danger"
                        ?disabled="${busy}"
                        @click="${() => void this.deleteChild(child, record.uid)}"
                    >
                        ${icon("remove")}
                        <span class="button-label">${this.text(actionLabelKey("remove"))}</span>
                    </button>
                    ${hidden ? html`<span class="state">${this.text(stateLabelKey("hidden"))}</span>` : nothing}
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
  renderNewChild(child) {
    const target = newChildTarget(child);
    const defaults = initialValues(fieldsOfChild(child));
    const edit = this.edits.of(target);
    return html`
            <div class="child child-new">
                ${this.renderGeneralErrors(target)}
                ${fieldsOfChild(child).map((definition) => {
      const field = definition.name;
      return html`
                        <modern-extbase-frontend-edit-field
                            data-focus="${focusKey(target, field)}"
                            .definition="${definition}"
                            .scope="${child}"
                            .labels="${this.labels}"
                            .serverValue="${""}"
                            .draftValue="${this.edits.draftOf(target, field, defaults[field] ?? "")}"
                            .editing="${true}"
                            .busy="${(edit == null ? void 0 : edit.busy) ?? false}"
                            .recordMode="${true}"
                            .errors="${this.edits.errorsOf(target, field)}"
                            @field-input="${(event) => this.onInput(target, field, event.detail.value)}"
                            @field-apply="${() => void this.addChild(child)}"
                            @field-cancel="${() => this.cancelRecord(target)}"
                        ></modern-extbase-frontend-edit-field>
                    `;
    })}
                <div class="child-actions">
                    <button
                        type="button"
                        data-variant="primary"
                        ?disabled="${(edit == null ? void 0 : edit.busy) ?? false}"
                        @click="${() => void this.addChild(child)}"
                    >
                        ${icon("add")}
                        <span class="button-label">${this.text(actionLabelKey("add"))}</span>
                    </button>
                </div>
            </div>
        `;
  }
  renderGeneralErrors(target) {
    const messages = this.edits.generalErrorsOf(target);
    if (messages.length === 0) {
      return nothing;
    }
    return html`
            <ul class="errors" role="alert">
                ${messages.map((message) => html`<li>${message}</li>`)}
            </ul>
        `;
  }
  beginField(profile, target, field) {
    this.edits = this.edits.beginField(target, field, fieldValue(profile, target, field));
    this.pendingFocus = focusKey(target, field);
  }
  onInput(target, field, value) {
    this.edits = this.edits.setDraft(target, field, value);
  }
  /**
   * Cancel: the draft is discarded and the field shows the stored value again.
   *
   * In a whole record edit the whole session goes, because cancelling one
   * control of a form that submits together is not a state the user asked
   * for.
   */
  cancelField(target, field) {
    var _a;
    if (((_a = this.edits.of(target)) == null ? void 0 : _a.mode) === "record") {
      this.cancelRecord(target);
      return;
    }
    this.edits = this.edits.endField(target, field);
  }
  /**
   * Apply: sends **only this field**, through the partial save endpoint.
   */
  async applyField(target, field) {
    if (isNewChildTarget(target)) {
      await this.addChild(target.child);
      return;
    }
    const edit = this.edits.of(target);
    if (edit === null || edit.busy) {
      return;
    }
    if (edit.mode === "record") {
      await this.submitRecord(target);
      return;
    }
    const value = this.edits.draftOf(target, field, "");
    await this.send(
      target,
      "saveField",
      (profile) => fieldPayload(profile.uid, target, field, value),
      () => {
        this.edits = this.edits.endField(target, field);
      }
    );
  }
  beginRecord(profile, target) {
    const values = recordValues(profile, target);
    this.edits = this.edits.beginRecord(target, values);
    const first = Object.keys(values).at(0);
    this.pendingFocus = first === void 0 ? null : focusKey(target, first);
  }
  cancelRecord(target) {
    this.edits = this.edits.endRecord(target);
  }
  async submitRecord(target) {
    const edit = this.edits.of(target);
    if (edit === null || edit.busy) {
      return;
    }
    const data = this.draftValues(target);
    await this.send(
      target,
      "save",
      (profile) => recordPayload(profile.uid, target, data),
      () => {
        this.edits = this.edits.endRecord(target);
      }
    );
  }
  async addChild(child) {
    const target = newChildTarget(child);
    if (this.edits.isBusy(target)) {
      return;
    }
    const data = this.draftValues(target);
    await this.send(
      target,
      "addChild",
      (profile) => addChildPayload(profile.uid, child, data),
      () => {
        this.edits = this.edits.endRecord(target);
      }
    );
  }
  /**
   * Removes one child — and the endpoint deletes the row rather than only
   * detaching it, which would leave it behind with no parent and no sorting.
   *
   * Named `deleteChild` because `removeChild` is `Node.removeChild()`, which
   * this element inherits.
   */
  async deleteChild(child, childUid) {
    const target = childTarget(child, childUid);
    await this.send(
      target,
      "removeChild",
      (profile) => removeChildPayload(profile.uid, child, childUid),
      () => {
        this.edits = this.edits.endRecord(target);
      }
    );
  }
  /**
   * Moves one child, sending the **whole** resulting order.
   *
   * A move that would leave the collection produces the unchanged order, and
   * the request is then not sent at all — the endpoint would accept it and
   * write the same sorting back, which is a write nobody asked for.
   */
  async moveChild(child, childUid, offset) {
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
      "reorderChildren",
      (current) => reorderPayload(current.uid, child, order),
      () => {
        this.pendingFocus = null;
      }
    );
  }
  async setChildVisibility(child, childUid, hidden) {
    const target = childTarget(child, childUid);
    await this.send(
      target,
      "setChildVisibility",
      (profile) => visibilityPayload(profile.uid, child, childUid, hidden),
      () => {
        this.pendingFocus = null;
      }
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
  async uploadImage(file) {
    this.imageRejected = false;
    const result = await this.send(
      profileTarget,
      "uploadImage",
      (profile) => imageUploadBody(profile.uid, file),
      () => {
        this.pendingFocus = null;
      }
    );
    this.imageRejected = result !== null && result.kind !== "success";
  }
  /**
   * Removes the stored image.
   *
   * An ordinary JSON call: there is nothing to transfer, so nothing about
   * multipart applies. Named `clearImage` for the same reason `deleteChild` is
   * not `removeChild` — `removeImage` would read like a DOM method on an
   * element, and this class already inherits one collision of that kind.
   */
  async clearImage() {
    this.imageRejected = false;
    await this.send(
      profileTarget,
      "removeImage",
      (profile) => removeImagePayload(profile.uid),
      () => {
        this.pendingFocus = null;
      }
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
  async send(target, action, body, onSuccess) {
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
  applyResult(result, target, onSuccess) {
    if (result.kind === "success") {
      this.profile = result.profile;
      this.edits = this.edits.clearErrors(target);
      onSuccess();
      return;
    }
    if (result.kind === "validation") {
      this.edits = this.edits.applyErrors(target, result.fieldErrors, result.generalErrors);
      const first = Object.keys(result.fieldErrors).at(0);
      this.pendingFocus = first === void 0 ? null : focusKey(target, first);
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
  draftValues(target) {
    const profile = this.profile;
    const defaults = initialValues(fieldsOf(target));
    const values = {};
    for (const definition of fieldsOf(target)) {
      const stored = profile === null || isNewChildTarget(target) ? defaults[definition.name] ?? "" : fieldValue(profile, target, definition.name);
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
  requestErrorText(status) {
    const specific = `error.request.${status}`;
    return this.labels[specific] ?? this.text("error.request");
  }
  text(key) {
    return label(this.labels, key);
  }
};
/*
 * This element is the only one that carries {@see tokens}, and that is a
 * requirement rather than a tidy-up — the token module's docblock explains
 * why declaring them on a child would break a site's ability to override
 * them.
 */
ProfileEditElement.styles = [
  tokens,
  controls,
  css`
            :host {
                display: block;
                max-width: var(--frontend-edit-measure);
            }

            /*
             * One record is a stack of fields, and the gap between them is the
             * only thing separating them — the field itself carries no vertical
             * padding, so there is a single place that decides how far apart two
             * fields sit.
             */
            .record {
                display: grid;
                gap: var(--frontend-edit-gap-field);
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
            /*
             * A section is the heading, the list, and the form that adds to it.
             * The gap between those three is the record step rather than the
             * field step: they are separate things, not lines of one thing.
             */
            .children {
                display: grid;
                gap: var(--frontend-edit-gap-record);
                border-top: var(--frontend-edit-border-width) solid var(--frontend-edit-color-border);
                margin-top: var(--frontend-edit-gap-section);
                padding-top: var(--frontend-edit-gap-record);
            }

            .children h3 {
                margin: 0;
            }

            .children-list {
                list-style: none;
                margin: 0;
                padding: 0;
                display: grid;
                gap: var(--frontend-edit-gap-record);
            }

            /*
             * A child is one record inside another, and the marker on its
             * leading edge is what says so. It is the accent rather than the
             * text colour because it is a structural cue and not content.
             */
            /*
             * A grid rather than a plain block, because a child holds a record
             * and its toolbar as siblings and nothing else would space them.
             * The record's own fields dropped their padding, so this is where
             * that separation now comes from.
             */
            .child {
                display: grid;
                gap: var(--frontend-edit-gap-field);
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
        `
];
__decorateClass([
  state()
], ProfileEditElement.prototype, "profile", 2);
__decorateClass([
  state()
], ProfileEditElement.prototype, "edits", 2);
__decorateClass([
  state()
], ProfileEditElement.prototype, "labels", 2);
__decorateClass([
  state()
], ProfileEditElement.prototype, "imageRejected", 2);
ProfileEditElement = __decorateClass([
  customElement("modern-extbase-frontend-edit-profile")
], ProfileEditElement);
function focusKey(target, field) {
  return `${targetKey(target)}|${field}`;
}
function sameOrder(one, other) {
  return one.length === other.length && one.every((uid, index) => uid === other[index]);
}
export {
  ProfileEditElement
};
