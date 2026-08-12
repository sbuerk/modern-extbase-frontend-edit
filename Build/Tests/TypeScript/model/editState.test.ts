/**
 * The edit sessions, and the two rules they exist to enforce.
 *
 * **Cancel restores the last server known value.** The session holds a draft and
 * nothing else; ending it removes the draft, and what a control then shows is
 * whatever `fieldValue()` answers for the *current* state. The pair is asserted
 * together below, because either half alone can look right while the composition
 * is wrong — a draft that survives `endFieldEdit()` reverts a field to what the
 * user typed, which is the exact opposite of cancelling.
 *
 * **A failed save keeps what was typed.** A `422` adds errors and leaves the
 * session, its open fields and its drafts standing.
 *
 * **A draft is content, not a by-product of an open field.** A session whose
 * only content is a draft is a session worth keeping — see the block on the add
 * form below for what it costs when it is not.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { EditMap } from '../../../Sources/TypeScript/model/editState.js';
import {
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
    setDraft,
} from '../../../Sources/TypeScript/model/editState.js';
import { fieldValue, parseProfileRecord, recordValues } from '../../../Sources/TypeScript/model/profileRecord.js';
import { childTarget, newChildTarget, profileTarget } from '../../../Sources/TypeScript/model/recordTarget.js';
import type { ProfileRecord } from '../../../Sources/TypeScript/model/types.js';
import { profileDocument, profileDocumentWith } from '../profileDocument.js';

function parsed(document: unknown = profileDocument): ProfileRecord {
    const profile = parseProfileRecord(document);
    assert.notEqual(profile, null, 'the fixture document has to parse');

    return profile as ProfileRecord;
}

/**
 * What a control shows: the draft when there is one, the state otherwise. This
 * is the composition the component renders with, so it is the one asserted.
 */
function shown(edits: EditMap, profile: ProfileRecord, field: string): string {
    return draftOf(edits, profileTarget, field, fieldValue(profile, profileTarget, field));
}

describe('a single field session', (): void => {
    it('starts from the value it was seeded with and records what is typed', (): void => {
        const profile = parsed();
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', fieldValue(profile, profileTarget, 'firstname'));

        assert.ok(isEditing(edits, profileTarget, 'firstname'));
        assert.equal(shown(edits, profile, 'firstname'), 'Ada');

        edits = setDraft(edits, profileTarget, 'firstname', 'Augusta');

        assert.equal(shown(edits, profile, 'firstname'), 'Augusta');
    });

    it('leaves an already open field alone, so a second click keeps the draft', (): void => {
        const profile = parsed();
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', 'Ada');
        edits = setDraft(edits, profileTarget, 'firstname', 'Augusta');
        const again = beginFieldEdit(edits, profileTarget, 'firstname', 'Ada');

        assert.equal(again, edits, 'the map is answered unchanged');
        assert.equal(shown(again, profile, 'firstname'), 'Augusta');
    });

    it('keeps the sessions of two fields of one record apart', (): void => {
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', 'Ada');
        edits = beginFieldEdit(edits, profileTarget, 'lastname', 'Lovelace');
        edits = setDraft(edits, profileTarget, 'lastname', 'King');
        edits = endFieldEdit(edits, profileTarget, 'lastname');

        assert.ok(isEditing(edits, profileTarget, 'firstname'));
        assert.equal(isEditing(edits, profileTarget, 'lastname'), false);
    });

    it('keys a session by the record it belongs to', (): void => {
        const edits = beginFieldEdit(emptyEditMap(), childTarget('address', 8), 'line1', 'Analytical Engine');

        assert.ok(isEditing(edits, childTarget('address', 8), 'line1'));
        assert.equal(isEditing(edits, childTarget('address', 9), 'line1'), false);
        assert.equal(isEditing(edits, childTarget('email', 8), 'line1'), false);
        assert.equal(isEditing(edits, profileTarget, 'line1'), false);
    });
});

describe('cancelling a field', (): void => {
    it('reverts to the value the state currently holds', (): void => {
        const profile = parsed();
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', 'Ada');
        edits = setDraft(edits, profileTarget, 'firstname', 'Augusta');
        edits = endFieldEdit(edits, profileTarget, 'firstname');

        assert.equal(shown(edits, profile, 'firstname'), 'Ada');
    });

    it('reverts the cancelled field while a session that survives keeps its other draft', (): void => {
        // Deliberately with a second field open. With only one, the session is
        // dropped as empty and the fallback is reached whether or not the draft
        // was discarded — so a single field cancel cannot tell the two apart.
        const profile = parsed();
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', 'Ada');
        edits = beginFieldEdit(edits, profileTarget, 'lastname', 'Lovelace');
        edits = setDraft(edits, profileTarget, 'firstname', 'Augusta');
        edits = setDraft(edits, profileTarget, 'lastname', 'King');
        edits = endFieldEdit(edits, profileTarget, 'firstname');

        assert.equal(shown(edits, profile, 'firstname'), 'Ada', 'the cancelled draft is gone');
        assert.equal(shown(edits, profile, 'lastname'), 'King', 'the other one is not');
    });

    it('reverts to the last successful save and not to the value at page load', (): void => {
        // One save has already succeeded: the server normalised "Ada Augusta"
        // into "Augusta", and that is now the state. A second edit is started,
        // typed into, and cancelled.
        const atPageLoad = parsed();
        const afterFirstSave = parsed(profileDocumentWith({ firstname: 'Augusta' }));

        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', fieldValue(afterFirstSave, profileTarget, 'firstname'));
        edits = setDraft(edits, profileTarget, 'firstname', 'typed but discarded');
        edits = endFieldEdit(edits, profileTarget, 'firstname');

        assert.equal(shown(edits, afterFirstSave, 'firstname'), 'Augusta');
        assert.notEqual(
            shown(edits, afterFirstSave, 'firstname'),
            fieldValue(atPageLoad, profileTarget, 'firstname'),
            'the value the page was loaded with is gone and must not come back',
        );
    });

    it('drops a session that has no open field, no error and no request in flight', (): void => {
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', 'Ada');

        assert.notEqual(editOf(edits, profileTarget), null);

        edits = endFieldEdit(edits, profileTarget, 'firstname');

        assert.equal(editOf(edits, profileTarget), null, 'nothing worth keeping is kept');
        assert.equal(edits.size, 0);
    });

    it('keeps a session that still has something to report', (): void => {
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', 'Ada');
        edits = applyErrors(edits, profileTarget, {}, ['The record as a whole is wrong.']);
        edits = endFieldEdit(edits, profileTarget, 'firstname');

        assert.notEqual(editOf(edits, profileTarget), null);
        assert.deepEqual(generalErrorsOf(edits, profileTarget), ['The record as a whole is wrong.']);
    });

    it('is a no-op on a record that is not being edited', (): void => {
        const edits = emptyEditMap();

        assert.equal(endFieldEdit(edits, profileTarget, 'firstname'), edits);
    });
});

/**
 * The add form, which is the one record that types into a session it never
 * opened.
 *
 * Its controls are always rendered, so nothing ever calls `beginFieldEdit()`
 * for it and the only thing its session ever holds is a draft. `write()` used
 * to drop a session that had no open field *whatever was in its drafts*, so
 * every keystroke was discarded on the way out: `addChild()` submitted the
 * initial values while the control on screen still showed what had been typed,
 * and the server answered `422` for the empty required field.
 *
 * ## Why the tests above cannot see this
 *
 * Every one of them that reaches the drop has a field open — the two field
 * cancel test says so in as many words, and the single field cancel asserts
 * through `draftOf()` with a fallback that is *also* the value it expects, so a
 * dropped session and a discarded draft answer identically. The drop therefore
 * never fires on a session that still has something to lose.
 *
 * The assertion has to be made on a session whose **only** content is a draft,
 * and against a fallback the draft differs from. A test that opens a field
 * first is a test of `beginFieldEdit()`, and it goes green against the defect.
 */
describe('a record whose controls are always open', (): void => {
    it('keeps a draft that was recorded without an open field', (): void => {
        const target = newChildTarget('email');
        const edits = setDraft(emptyEditMap(), target, 'email', 'third@example.org');

        assert.notEqual(editOf(edits, target), null, 'the session is not dropped as empty');
        assert.deepEqual(editOf(edits, target)?.fields, [], 'and it never opened a field');

        // The fallback is what the add form seeds its controls with, i.e. what
        // the payload would carry if the draft were gone.
        assert.equal(draftOf(edits, target, 'email', ''), 'third@example.org');
    });

    it('keeps the drafts of a whole form typed field by field', (): void => {
        const target = newChildTarget('email');
        let edits = setDraft(emptyEditMap(), target, 'type', 'business');
        edits = setDraft(edits, target, 'email', 'third@example.org');

        assert.equal(draftOf(edits, target, 'type', 'others'), 'business');
        assert.equal(draftOf(edits, target, 'email', ''), 'third@example.org');
    });

    it('is still dropped when the form is cancelled', (): void => {
        const target = newChildTarget('email');
        const edits = endRecordEdit(setDraft(emptyEditMap(), target, 'email', 'third@example.org'), target);

        assert.equal(editOf(edits, target), null, 'keeping a draft is not keeping it forever');
    });
});

describe('a whole record session', (): void => {
    it('opens every writable field seeded from the state', (): void => {
        const profile = parsed();
        const edits = beginRecordEdit(emptyEditMap(), profileTarget, recordValues(profile, profileTarget));

        assert.equal(editOf(edits, profileTarget)?.mode, 'record');
        assert.deepEqual(editOf(edits, profileTarget)?.fields, ['shortname', 'firstname', 'lastname', 'birthday', 'bio']);
        assert.equal(shown(edits, profile, 'bio'), 'Mathematician.');
    });

    it('replaces a half finished single field session rather than merging with it', (): void => {
        const profile = parsed();
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', 'Ada');
        edits = setDraft(edits, profileTarget, 'firstname', 'never submitted');
        edits = applyErrors(edits, profileTarget, { firstname: ['too long'] }, []);
        edits = beginRecordEdit(edits, profileTarget, recordValues(profile, profileTarget));

        assert.equal(shown(edits, profile, 'firstname'), 'Ada');
        assert.deepEqual(errorsOf(edits, profileTarget, 'firstname'), []);
    });

    it('ends by discarding the whole session', (): void => {
        const profile = parsed();
        let edits = beginRecordEdit(emptyEditMap(), profileTarget, recordValues(profile, profileTarget));
        edits = applyErrors(edits, profileTarget, { bio: ['too long'] }, ['nope']);
        edits = endRecordEdit(edits, profileTarget);

        assert.equal(editOf(edits, profileTarget), null);
    });
});

describe('applying a 422', (): void => {
    it('puts a message at the field it names and keeps the draft', (): void => {
        const profile = parsed();
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'shortname', 'ada');
        edits = setDraft(edits, profileTarget, 'shortname', '');
        edits = applyErrors(edits, profileTarget, { shortname: ['Must not be empty.'] }, []);

        assert.deepEqual(errorsOf(edits, profileTarget, 'shortname'), ['Must not be empty.']);
        assert.ok(isEditing(edits, profileTarget, 'shortname'), 'the session stays open');
        assert.equal(shown(edits, profile, 'shortname'), '', 'what was typed is not discarded');
    });

    it('keeps an error that names no field on the record', (): void => {
        const edits = applyErrors(emptyEditMap(), profileTarget, {}, ['The record as a whole is wrong.']);

        assert.deepEqual(generalErrorsOf(edits, profileTarget), ['The record as a whole is wrong.']);
        assert.deepEqual(errorsOf(edits, profileTarget, 'shortname'), []);
    });

    it('creates a session for a relation operation that has no open control', (): void => {
        const edits = applyErrors(emptyEditMap(), childTarget('address', 8), {}, ['Reordering failed.']);

        assert.notEqual(editOf(edits, childTarget('address', 8)), null);
        assert.deepEqual(generalErrorsOf(edits, childTarget('address', 8)), ['Reordering failed.']);
        assert.deepEqual(generalErrorsOf(edits, profileTarget), [], 'and nowhere else');
    });

    it('clears the errors of fields the new answer does not mention', (): void => {
        let edits = applyErrors(emptyEditMap(), profileTarget, {
            shortname: ['Must not be empty.'],
            bio: ['Too long.'],
        }, ['and generally wrong']);
        edits = applyErrors(edits, profileTarget, { bio: ['Still too long.'] }, []);

        assert.deepEqual(errorsOf(edits, profileTarget, 'shortname'), [], 'the response is the complete answer');
        assert.deepEqual(errorsOf(edits, profileTarget, 'bio'), ['Still too long.']);
        assert.deepEqual(generalErrorsOf(edits, profileTarget), []);
    });

    it('is dropped again by clearErrors before the next attempt', (): void => {
        let edits = beginFieldEdit(emptyEditMap(), profileTarget, 'shortname', 'ada');
        edits = setDraft(edits, profileTarget, 'shortname', '');
        edits = applyErrors(edits, profileTarget, { shortname: ['Must not be empty.'] }, ['nope']);
        edits = clearErrors(edits, profileTarget);

        assert.deepEqual(errorsOf(edits, profileTarget, 'shortname'), []);
        assert.deepEqual(generalErrorsOf(edits, profileTarget), []);
        assert.ok(isEditing(edits, profileTarget, 'shortname'), 'the open field survives');
        assert.equal(draftOf(edits, profileTarget, 'shortname', 'ada'), '', 'and so does the draft');
    });

    it('does not resurrect a session that clearErrors would empty', (): void => {
        const edits = clearErrors(applyErrors(emptyEditMap(), profileTarget, { shortname: ['x'] }, []), profileTarget);

        assert.equal(editOf(edits, profileTarget), null);
    });
});

describe('the busy flag', (): void => {
    it('keeps a session alive on its own while a request is in flight', (): void => {
        let edits = setBusy(emptyEditMap(), childTarget('address', 8), true);

        assert.ok(isBusy(edits, childTarget('address', 8)));
        assert.notEqual(editOf(edits, childTarget('address', 8)), null);

        edits = setBusy(edits, childTarget('address', 8), false);

        assert.equal(isBusy(edits, childTarget('address', 8)), false);
        assert.equal(editOf(edits, childTarget('address', 8)), null, 'and is dropped when it ends');
    });
});

describe('immutability', (): void => {
    it('never writes into the map it was given', (): void => {
        const before = beginFieldEdit(emptyEditMap(), profileTarget, 'firstname', 'Ada');

        setDraft(before, profileTarget, 'firstname', 'Augusta');
        applyErrors(before, profileTarget, { firstname: ['x'] }, []);
        endFieldEdit(before, profileTarget, 'firstname');
        endRecordEdit(before, profileTarget);

        assert.equal(draftOf(before, profileTarget, 'firstname', ''), 'Ada');
        assert.deepEqual(errorsOf(before, profileTarget, 'firstname'), []);
        assert.ok(isEditing(before, profileTarget, 'firstname'));
    });
});
