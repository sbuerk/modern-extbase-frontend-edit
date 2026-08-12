/* Generated from Build/Sources/TypeScript — do not edit. */
import { targetKey } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/recordTarget.js";
const emptyEdit = {
  mode: "field",
  fields: [],
  drafts: {},
  fieldErrors: {},
  generalErrors: [],
  busy: false
};
function emptyEditMap() {
  return /* @__PURE__ */ new Map();
}
function editOf(edits, target) {
  return edits.get(targetKey(target)) ?? null;
}
function isEditing(edits, target, field) {
  var _a;
  return ((_a = editOf(edits, target)) == null ? void 0 : _a.fields.includes(field)) ?? false;
}
function isBusy(edits, target) {
  var _a;
  return ((_a = editOf(edits, target)) == null ? void 0 : _a.busy) ?? false;
}
function draftOf(edits, target, field, fallback) {
  const edit = editOf(edits, target);
  if (edit === null || !(field in edit.drafts)) {
    return fallback;
  }
  return edit.drafts[field] ?? fallback;
}
function errorsOf(edits, target, field) {
  var _a;
  return ((_a = editOf(edits, target)) == null ? void 0 : _a.fieldErrors[field]) ?? [];
}
function generalErrorsOf(edits, target) {
  var _a;
  return ((_a = editOf(edits, target)) == null ? void 0 : _a.generalErrors) ?? [];
}
function beginFieldEdit(edits, target, field, value) {
  const edit = editOf(edits, target) ?? emptyEdit;
  if (edit.fields.includes(field)) {
    return edits;
  }
  return write(edits, target, {
    ...edit,
    fields: [...edit.fields, field],
    drafts: { ...edit.drafts, [field]: value },
    fieldErrors: withoutKey(edit.fieldErrors, field)
  });
}
function beginRecordEdit(edits, target, values) {
  return write(edits, target, {
    ...emptyEdit,
    mode: "record",
    fields: Object.keys(values),
    drafts: { ...values }
  });
}
function setDraft(edits, target, field, value) {
  const edit = editOf(edits, target) ?? emptyEdit;
  return write(edits, target, {
    ...edit,
    drafts: { ...edit.drafts, [field]: value }
  });
}
function endFieldEdit(edits, target, field) {
  const edit = editOf(edits, target);
  if (edit === null) {
    return edits;
  }
  return write(edits, target, {
    ...edit,
    fields: edit.fields.filter((name) => name !== field),
    drafts: withoutKey(edit.drafts, field),
    fieldErrors: withoutKey(edit.fieldErrors, field)
  });
}
function endRecordEdit(edits, target) {
  const remaining = new Map(edits);
  remaining.delete(targetKey(target));
  return remaining;
}
function setBusy(edits, target, busy) {
  const edit = editOf(edits, target) ?? emptyEdit;
  return write(edits, target, { ...edit, busy });
}
function applyErrors(edits, target, fieldErrors, generalErrors) {
  const edit = editOf(edits, target) ?? emptyEdit;
  return write(edits, target, {
    ...edit,
    fieldErrors: { ...fieldErrors },
    generalErrors: [...generalErrors]
  });
}
function clearErrors(edits, target) {
  const edit = editOf(edits, target);
  if (edit === null) {
    return edits;
  }
  return write(edits, target, { ...edit, fieldErrors: {}, generalErrors: [] });
}
function write(edits, target, edit) {
  const next = new Map(edits);
  const key = targetKey(target);
  if (edit.fields.length === 0 && Object.keys(edit.drafts).length === 0 && edit.generalErrors.length === 0 && Object.keys(edit.fieldErrors).length === 0 && !edit.busy) {
    next.delete(key);
    return next;
  }
  next.set(key, edit);
  return next;
}
function withoutKey(values, key) {
  const remaining = { ...values };
  delete remaining[key];
  return remaining;
}
export {
  applyErrors,
  beginFieldEdit,
  beginRecordEdit,
  clearErrors,
  draftOf,
  editOf,
  emptyEditMap,
  endFieldEdit,
  endRecordEdit,
  errorsOf,
  generalErrorsOf,
  isBusy,
  isEditing,
  setBusy,
  setDraft
};
