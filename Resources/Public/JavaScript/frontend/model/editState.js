/* Generated from Build/Sources/TypeScript — do not edit. */
import { targetKey } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/recordTarget.js";
const emptyEdit = Object.freeze({
  mode: "field",
  fields: [],
  drafts: {},
  fieldErrors: {},
  generalErrors: [],
  busy: false
});
class EditSessions {
  constructor(sessions) {
    this.sessions = sessions;
  }
  static empty() {
    return new EditSessions(/* @__PURE__ */ new Map());
  }
  /**
   * How many records have a session at all.
   *
   * Exposed because {@see write} *drops* a session that carries nothing worth
   * keeping rather than storing an empty one, and the difference between those
   * two is invisible through {@see of}: both answer `null`. Without this, the
   * test that the dropping happens could only assert the symptom.
   */
  get size() {
    return this.sessions.size;
  }
  of(target) {
    return this.sessions.get(targetKey(target)) ?? null;
  }
  isEditing(target, field) {
    var _a;
    return ((_a = this.of(target)) == null ? void 0 : _a.fields.includes(field)) ?? false;
  }
  isBusy(target) {
    var _a;
    return ((_a = this.of(target)) == null ? void 0 : _a.busy) ?? false;
  }
  /**
   * The value shown in a control: what was typed, or the fallback.
   *
   * The fallback is the last server known value, which is also what makes a
   * session that was never typed into submit the stored values unchanged.
   */
  draftOf(target, field, fallback) {
    const edit = this.of(target);
    if (edit === null || !(field in edit.drafts)) {
      return fallback;
    }
    return edit.drafts[field] ?? fallback;
  }
  errorsOf(target, field) {
    var _a;
    return ((_a = this.of(target)) == null ? void 0 : _a.fieldErrors[field]) ?? [];
  }
  generalErrorsOf(target) {
    var _a;
    return ((_a = this.of(target)) == null ? void 0 : _a.generalErrors) ?? [];
  }
  /**
   * Switches one field to a control, seeded with the given value.
   *
   * An already open field is left exactly as it is, drafts included: a second
   * click on an edit affordance must not throw away what is in the control.
   */
  beginField(target, field, value) {
    const edit = this.of(target) ?? emptyEdit;
    if (edit.fields.includes(field)) {
      return this;
    }
    return this.write(target, {
      ...edit,
      fields: [...edit.fields, field],
      drafts: { ...edit.drafts, [field]: value },
      fieldErrors: withoutKey(edit.fieldErrors, field)
    });
  }
  /**
   * Switches every field of a record to a control at once.
   *
   * Replaces whatever was open on that record: a whole record edit is a
   * different intent, and mixing it with half-finished single field drafts
   * would make the submit send values the user never saw together.
   */
  beginRecord(target, values) {
    return this.write(target, {
      ...emptyEdit,
      mode: "record",
      fields: Object.keys(values),
      drafts: { ...values }
    });
  }
  setDraft(target, field, value) {
    const edit = this.of(target) ?? emptyEdit;
    return this.write(target, {
      ...edit,
      drafts: { ...edit.drafts, [field]: value }
    });
  }
  /**
   * Ends the editing of one field, discarding its draft and its errors.
   *
   * This is both *cancel* and the successful half of *apply*: in either case
   * the field goes back to rendering the last server known value, which after
   * a successful save is the value the server persisted. A session that has no
   * field left and nothing to report is dropped.
   */
  endField(target, field) {
    const edit = this.of(target);
    if (edit === null) {
      return this;
    }
    return this.write(target, {
      ...edit,
      fields: edit.fields.filter((name) => name !== field),
      drafts: withoutKey(edit.drafts, field),
      fieldErrors: withoutKey(edit.fieldErrors, field)
    });
  }
  /**
   * Ends the whole session of a record, discarding every draft and every
   * error.
   */
  endRecord(target) {
    const remaining = new Map(this.sessions);
    remaining.delete(targetKey(target));
    return new EditSessions(remaining);
  }
  setBusy(target, busy) {
    const edit = this.of(target) ?? emptyEdit;
    return this.write(target, { ...edit, busy });
  }
  /**
   * Puts the errors of a rejected write onto the record and its fields.
   *
   * The session is created when there is none, so that a relation operation —
   * a reorder, a removal, a visibility toggle — has somewhere to report a
   * failure even though it has no open control. Errors of fields that are not
   * mentioned are cleared, because the response is the complete answer about
   * this write.
   */
  applyErrors(target, fieldErrors, generalErrors) {
    const edit = this.of(target) ?? emptyEdit;
    return this.write(target, {
      ...edit,
      fieldErrors: { ...fieldErrors },
      generalErrors: [...generalErrors]
    });
  }
  /**
   * Clears every error of a record, keeping its drafts and its open fields.
   *
   * Called before a write, so that a second attempt does not show the previous
   * answer next to the new one.
   */
  clearErrors(target) {
    const edit = this.of(target);
    if (edit === null) {
      return this;
    }
    return this.write(target, { ...edit, fieldErrors: {}, generalErrors: [] });
  }
  /**
   * Writes a session back, dropping it when it carries nothing worth keeping.
   *
   * "Nothing worth keeping" is: no open field, no draft, no error, and no
   * request in flight. Keeping such an entry would leave an empty session
   * behind after every cancelled edit and make `of()` answer with something
   * that means nothing.
   *
   * The draft condition is not symmetry for its own sake. A record whose
   * controls are always visible — the form that adds a child — never opens a
   * field, so every keystroke it records would be dropped again on the way
   * out, and the submitted payload would carry the defaults while the control
   * on screen showed what the user typed. Cancelling still drops the session,
   * because `endField()` removes a field and its draft together.
   */
  write(target, edit) {
    const next = new Map(this.sessions);
    const key = targetKey(target);
    if (edit.fields.length === 0 && Object.keys(edit.drafts).length === 0 && edit.generalErrors.length === 0 && Object.keys(edit.fieldErrors).length === 0 && !edit.busy) {
      next.delete(key);
      return new EditSessions(next);
    }
    next.set(key, edit);
    return new EditSessions(next);
  }
}
function withoutKey(values, key) {
  const remaining = { ...values };
  delete remaining[key];
  return remaining;
}
export {
  EditSessions
};
