/**
 * What is currently being edited, per record.
 *
 * One session per record — keyed by {@see targetKey} — holding the fields that
 * are switched to a control, the value typed into each of them, the validation
 * errors the server returned for them, and whether a request is in flight. Both
 * editing modes are the same session with a different `mode`:
 *
 * - `field` — one field at a time, applied on its own through `saveField`.
 *   Several fields of one record may be open at once; each keeps its own draft
 *   and its own errors.
 * - `record` — every field of the record at once, submitted through `save`.
 *
 * ## Why the drafts live here and not in the DOM
 *
 * Two rules require it. **A failed save must not discard what the user typed**,
 * so a `422` leaves the session standing with its drafts and only adds the
 * errors. And **the server is the source of truth**, so a successful save
 * replaces the state and *ends* the session rather than writing anything back
 * into a control — which is what makes a normalised value visibly become the
 * server's value.
 *
 * Every function returns a new map and mutates nothing. That is what lets the
 * component treat the map as reactive state, and it is what makes the whole
 * module testable without a DOM.
 */
import type { RecordTarget } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/recordTarget.js';
import { targetKey } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/recordTarget.js';

export type EditMode = 'field' | 'record';

export interface RecordEdit {
    readonly mode: EditMode;
    /**
     * The fields currently switched to a control, in the order they were
     * opened.
     */
    readonly fields: readonly string[];
    readonly drafts: Readonly<Record<string, string>>;
    readonly fieldErrors: Readonly<Record<string, readonly string[]>>;
    /**
     * Errors that belong to the record rather than to one of its fields: the
     * `422` entries the server sent with `"field": null`, and the message shown
     * when a request failed for a reason that is not a validation failure.
     */
    readonly generalErrors: readonly string[];
    readonly busy: boolean;
}

export type EditMap = ReadonlyMap<string, RecordEdit>;

const emptyEdit: RecordEdit = {
    mode: 'field',
    fields: [],
    drafts: {},
    fieldErrors: {},
    generalErrors: [],
    busy: false,
};

export function emptyEditMap(): EditMap {
    return new Map<string, RecordEdit>();
}

export function editOf(edits: EditMap, target: RecordTarget): RecordEdit | null {
    return edits.get(targetKey(target)) ?? null;
}

export function isEditing(edits: EditMap, target: RecordTarget, field: string): boolean {
    return editOf(edits, target)?.fields.includes(field) ?? false;
}

export function isBusy(edits: EditMap, target: RecordTarget): boolean {
    return editOf(edits, target)?.busy ?? false;
}

/**
 * The value shown in a control: what was typed, or the fallback.
 *
 * The fallback is the last server known value, which is also what makes a
 * session that was never typed into submit the stored values unchanged.
 */
export function draftOf(edits: EditMap, target: RecordTarget, field: string, fallback: string): string {
    const edit = editOf(edits, target);
    if (edit === null || !(field in edit.drafts)) {
        return fallback;
    }

    return edit.drafts[field] ?? fallback;
}

export function errorsOf(edits: EditMap, target: RecordTarget, field: string): readonly string[] {
    return editOf(edits, target)?.fieldErrors[field] ?? [];
}

export function generalErrorsOf(edits: EditMap, target: RecordTarget): readonly string[] {
    return editOf(edits, target)?.generalErrors ?? [];
}

/**
 * Switches one field to a control, seeded with the given value.
 *
 * An already open field is left exactly as it is, drafts included: a second
 * click on an edit affordance must not throw away what is in the control.
 */
export function beginFieldEdit(
    edits: EditMap,
    target: RecordTarget,
    field: string,
    value: string,
): EditMap {
    const edit = editOf(edits, target) ?? emptyEdit;
    if (edit.fields.includes(field)) {
        return edits;
    }

    return write(edits, target, {
        ...edit,
        fields: [...edit.fields, field],
        drafts: { ...edit.drafts, [field]: value },
        fieldErrors: withoutKey(edit.fieldErrors, field),
    });
}

/**
 * Switches every field of a record to a control at once.
 *
 * Replaces whatever was open on that record: a whole record edit is a different
 * intent, and mixing it with half-finished single field drafts would make the
 * submit send values the user never saw together.
 */
export function beginRecordEdit(
    edits: EditMap,
    target: RecordTarget,
    values: Readonly<Record<string, string>>,
): EditMap {
    return write(edits, target, {
        ...emptyEdit,
        mode: 'record',
        fields: Object.keys(values),
        drafts: { ...values },
    });
}

export function setDraft(edits: EditMap, target: RecordTarget, field: string, value: string): EditMap {
    const edit = editOf(edits, target) ?? emptyEdit;

    return write(edits, target, {
        ...edit,
        drafts: { ...edit.drafts, [field]: value },
    });
}

/**
 * Ends the editing of one field, discarding its draft and its errors.
 *
 * This is both *cancel* and the successful half of *apply*: in either case the
 * field goes back to rendering the last server known value, which after a
 * successful save is the value the server persisted. A session that has no
 * field left and nothing to report is dropped.
 */
export function endFieldEdit(edits: EditMap, target: RecordTarget, field: string): EditMap {
    const edit = editOf(edits, target);
    if (edit === null) {
        return edits;
    }

    return write(edits, target, {
        ...edit,
        fields: edit.fields.filter((name: string): boolean => name !== field),
        drafts: withoutKey(edit.drafts, field),
        fieldErrors: withoutKey(edit.fieldErrors, field),
    });
}

/**
 * Ends the whole session of a record, discarding every draft and every error.
 */
export function endRecordEdit(edits: EditMap, target: RecordTarget): EditMap {
    const remaining = new Map(edits);
    remaining.delete(targetKey(target));

    return remaining;
}

export function setBusy(edits: EditMap, target: RecordTarget, busy: boolean): EditMap {
    const edit = editOf(edits, target) ?? emptyEdit;

    return write(edits, target, { ...edit, busy });
}

/**
 * Puts the errors of a rejected write onto the record and its fields.
 *
 * The session is created when there is none, so that a relation operation —
 * a reorder, a removal, a visibility toggle — has somewhere to report a failure
 * even though it has no open control. Errors of fields that are not mentioned
 * are cleared, because the response is the complete answer about this write.
 */
export function applyErrors(
    edits: EditMap,
    target: RecordTarget,
    fieldErrors: Readonly<Record<string, readonly string[]>>,
    generalErrors: readonly string[],
): EditMap {
    const edit = editOf(edits, target) ?? emptyEdit;

    return write(edits, target, {
        ...edit,
        fieldErrors: { ...fieldErrors },
        generalErrors: [...generalErrors],
    });
}

/**
 * Clears every error of a record, keeping its drafts and its open fields.
 *
 * Called before a write, so that a second attempt does not show the previous
 * answer next to the new one.
 */
export function clearErrors(edits: EditMap, target: RecordTarget): EditMap {
    const edit = editOf(edits, target);
    if (edit === null) {
        return edits;
    }

    return write(edits, target, { ...edit, fieldErrors: {}, generalErrors: [] });
}

/**
 * Writes a session back, dropping it when it carries nothing worth keeping.
 *
 * "Nothing worth keeping" is: no open field, no draft, no error, and no request
 * in flight. Keeping such an entry would leave an empty session behind after
 * every cancelled edit and make `editOf()` answer with something that means
 * nothing.
 *
 * The draft condition is not symmetry for its own sake. A record whose controls
 * are always visible — the form that adds a child — never opens a field, so
 * every keystroke it records would be dropped again on the way out, and the
 * submitted payload would carry the defaults while the control on screen showed
 * what the user typed. Cancelling still drops the session, because
 * `endFieldEdit()` removes a field and its draft together.
 */
function write(edits: EditMap, target: RecordTarget, edit: RecordEdit): EditMap {
    const next = new Map(edits);
    const key = targetKey(target);
    if (
        edit.fields.length === 0
        && Object.keys(edit.drafts).length === 0
        && edit.generalErrors.length === 0
        && Object.keys(edit.fieldErrors).length === 0
        && !edit.busy
    ) {
        next.delete(key);

        return next;
    }
    next.set(key, edit);

    return next;
}

function withoutKey<T>(values: Readonly<Record<string, T>>, key: string): Record<string, T> {
    const remaining: Record<string, T> = { ...values };
    delete remaining[key];

    return remaining;
}
