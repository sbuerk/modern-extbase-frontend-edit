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
 * ## Why this is a class, and why it is still immutable
 *
 * Every method that changes something returns a **new** `EditSessions` and
 * mutates nothing. That is not a stylistic choice: lit notices a change only
 * when the property is assigned, so `this.edits = this.edits.beginField(…)` is
 * what makes the component re-render, and an in-place mutation would update the
 * state while leaving the surface stale.
 *
 * The same operations were fifteen exported functions that each took the map as
 * their first argument, which is an object written the long way round. Nothing
 * about the behaviour changed in making that explicit — the map is still
 * replaced rather than edited, the module still needs no DOM to be tested, and
 * the two private helpers below are now genuinely private rather than merely
 * unexported.
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

/**
 * The session every operation starts from when a record has none yet.
 *
 * Frozen because it is shared: every method below spreads it rather than
 * copying it first, and `readonly` in the interface above is erased at runtime.
 */
const emptyEdit: RecordEdit = Object.freeze({
    mode: 'field',
    fields: [],
    drafts: {},
    fieldErrors: {},
    generalErrors: [],
    busy: false,
});

export class EditSessions {
    /**
     * Written out rather than declared as a constructor parameter property: a
     * parameter property is not type-erasable syntax, and the test tree runs
     * these modules through node's type stripping rather than compiling them.
     */
    private readonly sessions: ReadonlyMap<string, RecordEdit>;

    private constructor(sessions: ReadonlyMap<string, RecordEdit>) {
        this.sessions = sessions;
    }

    public static empty(): EditSessions {
        return new EditSessions(new Map<string, RecordEdit>());
    }

    /**
     * How many records have a session at all.
     *
     * Exposed because {@see write} *drops* a session that carries nothing worth
     * keeping rather than storing an empty one, and the difference between those
     * two is invisible through {@see of}: both answer `null`. Without this, the
     * test that the dropping happens could only assert the symptom.
     */
    public get size(): number {
        return this.sessions.size;
    }

    public of(target: RecordTarget): RecordEdit | null {
        return this.sessions.get(targetKey(target)) ?? null;
    }

    public isEditing(target: RecordTarget, field: string): boolean {
        return this.of(target)?.fields.includes(field) ?? false;
    }

    public isBusy(target: RecordTarget): boolean {
        return this.of(target)?.busy ?? false;
    }

    /**
     * The value shown in a control: what was typed, or the fallback.
     *
     * The fallback is the last server known value, which is also what makes a
     * session that was never typed into submit the stored values unchanged.
     */
    public draftOf(target: RecordTarget, field: string, fallback: string): string {
        const edit = this.of(target);
        if (edit === null || !(field in edit.drafts)) {
            return fallback;
        }

        return edit.drafts[field] ?? fallback;
    }

    public errorsOf(target: RecordTarget, field: string): readonly string[] {
        return this.of(target)?.fieldErrors[field] ?? [];
    }

    public generalErrorsOf(target: RecordTarget): readonly string[] {
        return this.of(target)?.generalErrors ?? [];
    }

    /**
     * Switches one field to a control, seeded with the given value.
     *
     * An already open field is left exactly as it is, drafts included: a second
     * click on an edit affordance must not throw away what is in the control.
     */
    public beginField(target: RecordTarget, field: string, value: string): EditSessions {
        const edit = this.of(target) ?? emptyEdit;
        if (edit.fields.includes(field)) {
            return this;
        }

        return this.write(target, {
            ...edit,
            fields: [...edit.fields, field],
            drafts: { ...edit.drafts, [field]: value },
            fieldErrors: withoutKey(edit.fieldErrors, field),
        });
    }

    /**
     * Switches every field of a record to a control at once.
     *
     * Replaces whatever was open on that record: a whole record edit is a
     * different intent, and mixing it with half-finished single field drafts
     * would make the submit send values the user never saw together.
     */
    public beginRecord(target: RecordTarget, values: Readonly<Record<string, string>>): EditSessions {
        return this.write(target, {
            ...emptyEdit,
            mode: 'record',
            fields: Object.keys(values),
            drafts: { ...values },
        });
    }

    public setDraft(target: RecordTarget, field: string, value: string): EditSessions {
        const edit = this.of(target) ?? emptyEdit;

        return this.write(target, {
            ...edit,
            drafts: { ...edit.drafts, [field]: value },
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
    public endField(target: RecordTarget, field: string): EditSessions {
        const edit = this.of(target);
        if (edit === null) {
            return this;
        }

        return this.write(target, {
            ...edit,
            fields: edit.fields.filter((name: string): boolean => name !== field),
            drafts: withoutKey(edit.drafts, field),
            fieldErrors: withoutKey(edit.fieldErrors, field),
        });
    }

    /**
     * Ends the whole session of a record, discarding every draft and every
     * error.
     */
    public endRecord(target: RecordTarget): EditSessions {
        const remaining = new Map(this.sessions);
        remaining.delete(targetKey(target));

        return new EditSessions(remaining);
    }

    public setBusy(target: RecordTarget, busy: boolean): EditSessions {
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
    public applyErrors(
        target: RecordTarget,
        fieldErrors: Readonly<Record<string, readonly string[]>>,
        generalErrors: readonly string[],
    ): EditSessions {
        const edit = this.of(target) ?? emptyEdit;

        return this.write(target, {
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
    public clearErrors(target: RecordTarget): EditSessions {
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
    private write(target: RecordTarget, edit: RecordEdit): EditSessions {
        const next = new Map(this.sessions);
        const key = targetKey(target);
        if (
            edit.fields.length === 0
            && Object.keys(edit.drafts).length === 0
            && edit.generalErrors.length === 0
            && Object.keys(edit.fieldErrors).length === 0
            && !edit.busy
        ) {
            next.delete(key);

            return new EditSessions(next);
        }
        next.set(key, edit);

        return new EditSessions(next);
    }
}

function withoutKey<T>(values: Readonly<Record<string, T>>, key: string): Record<string, T> {
    const remaining: Record<string, T> = { ...values };
    delete remaining[key];

    return remaining;
}
