/**
 * The edit sessions, and the two rules they exist to enforce.
 *
 * **Cancel restores the last server known value.** The session holds a draft and
 * nothing else; ending it removes the draft, and what a control then shows is
 * whatever `fieldValue()` answers for the *current* state. The pair is asserted
 * together below, because either half alone can look right while the composition
 * is wrong — a draft that survives `endField()` reverts a field to what the
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
import { EditSessions } from '../../../Sources/TypeScript/frontend/model/editState.js';
import { fieldValue, parseProfileRecord, recordValues } from '../../../Sources/TypeScript/frontend/model/profileRecord.js';
import { childTarget, newChildTarget, profileTarget } from '../../../Sources/TypeScript/frontend/model/recordTarget.js';
import type { ProfileRecord } from '../../../Sources/TypeScript/frontend/model/types.js';
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
function shown(edits: EditSessions, profile: ProfileRecord, field: string): string {
    return edits.draftOf(profileTarget, field, fieldValue(profile, profileTarget, field));
}

describe('a single field session', (): void => {
    it('starts from the value it was seeded with and records what is typed', (): void => {
        const profile = parsed();
        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', fieldValue(profile, profileTarget, 'firstname'));

        assert.ok(edits.isEditing(profileTarget, 'firstname'));
        assert.equal(shown(edits, profile, 'firstname'), 'Ada');

        edits = edits.setDraft(profileTarget, 'firstname', 'Augusta');

        assert.equal(shown(edits, profile, 'firstname'), 'Augusta');
    });

    it('leaves an already open field alone, so a second click keeps the draft', (): void => {
        const profile = parsed();
        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', 'Ada');
        edits = edits.setDraft(profileTarget, 'firstname', 'Augusta');
        const again = edits.beginField(profileTarget, 'firstname', 'Ada');

        assert.equal(again, edits, 'the map is answered unchanged');
        assert.equal(shown(again, profile, 'firstname'), 'Augusta');
    });

    it('keeps the sessions of two fields of one record apart', (): void => {
        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', 'Ada');
        edits = edits.beginField(profileTarget, 'lastname', 'Lovelace');
        edits = edits.setDraft(profileTarget, 'lastname', 'King');
        edits = edits.endField(profileTarget, 'lastname');

        assert.ok(edits.isEditing(profileTarget, 'firstname'));
        assert.equal(edits.isEditing(profileTarget, 'lastname'), false);
    });

    it('keys a session by the record it belongs to', (): void => {
        const edits = EditSessions.empty().beginField(childTarget('address', 8), 'line1', 'Analytical Engine');

        assert.ok(edits.isEditing(childTarget('address', 8), 'line1'));
        assert.equal(edits.isEditing(childTarget('address', 9), 'line1'), false);
        assert.equal(edits.isEditing(childTarget('email', 8), 'line1'), false);
        assert.equal(edits.isEditing(profileTarget, 'line1'), false);
    });
});

describe('cancelling a field', (): void => {
    it('reverts to the value the state currently holds', (): void => {
        const profile = parsed();
        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', 'Ada');
        edits = edits.setDraft(profileTarget, 'firstname', 'Augusta');
        edits = edits.endField(profileTarget, 'firstname');

        assert.equal(shown(edits, profile, 'firstname'), 'Ada');
    });

    it('reverts the cancelled field while a session that survives keeps its other draft', (): void => {
        // Deliberately with a second field open. With only one, the session is
        // dropped as empty and the fallback is reached whether or not the draft
        // was discarded — so a single field cancel cannot tell the two apart.
        const profile = parsed();
        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', 'Ada');
        edits = edits.beginField(profileTarget, 'lastname', 'Lovelace');
        edits = edits.setDraft(profileTarget, 'firstname', 'Augusta');
        edits = edits.setDraft(profileTarget, 'lastname', 'King');
        edits = edits.endField(profileTarget, 'firstname');

        assert.equal(shown(edits, profile, 'firstname'), 'Ada', 'the cancelled draft is gone');
        assert.equal(shown(edits, profile, 'lastname'), 'King', 'the other one is not');
    });

    it('reverts to the last successful save and not to the value at page load', (): void => {
        // One save has already succeeded: the server normalised "Ada Augusta"
        // into "Augusta", and that is now the state. A second edit is started,
        // typed into, and cancelled.
        const atPageLoad = parsed();
        const afterFirstSave = parsed(profileDocumentWith({ firstname: 'Augusta' }));

        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', fieldValue(afterFirstSave, profileTarget, 'firstname'));
        edits = edits.setDraft(profileTarget, 'firstname', 'typed but discarded');
        edits = edits.endField(profileTarget, 'firstname');

        assert.equal(shown(edits, afterFirstSave, 'firstname'), 'Augusta');
        assert.notEqual(
            shown(edits, afterFirstSave, 'firstname'),
            fieldValue(atPageLoad, profileTarget, 'firstname'),
            'the value the page was loaded with is gone and must not come back',
        );
    });

    it('drops a session that has no open field, no error and no request in flight', (): void => {
        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', 'Ada');

        assert.notEqual(edits.of(profileTarget), null);

        edits = edits.endField(profileTarget, 'firstname');

        assert.equal(edits.of(profileTarget), null, 'nothing worth keeping is kept');
        assert.equal(edits.size, 0);
    });

    it('keeps a session that still has something to report', (): void => {
        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', 'Ada');
        edits = edits.applyErrors(profileTarget, {}, ['The record as a whole is wrong.']);
        edits = edits.endField(profileTarget, 'firstname');

        assert.notEqual(edits.of(profileTarget), null);
        assert.deepEqual(edits.generalErrorsOf(profileTarget), ['The record as a whole is wrong.']);
    });

    it('is a no-op on a record that is not being edited', (): void => {
        const edits = EditSessions.empty();

        assert.equal(edits.endField(profileTarget, 'firstname'), edits);
    });
});

/**
 * The add form, which is the one record that types into a session it never
 * opened.
 *
 * Its controls are always rendered, so nothing ever calls `beginField()`
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
 * first is a test of `beginField()`, and it goes green against the defect.
 */
describe('a record whose controls are always open', (): void => {
    it('keeps a draft that was recorded without an open field', (): void => {
        const target = newChildTarget('email');
        const edits = EditSessions.empty().setDraft(target, 'email', 'third@example.org');

        assert.notEqual(edits.of(target), null, 'the session is not dropped as empty');
        assert.deepEqual(edits.of(target)?.fields, [], 'and it never opened a field');

        // The fallback is what the add form seeds its controls with, i.e. what
        // the payload would carry if the draft were gone.
        assert.equal(edits.draftOf(target, 'email', ''), 'third@example.org');
    });

    it('keeps the drafts of a whole form typed field by field', (): void => {
        const target = newChildTarget('email');
        let edits = EditSessions.empty().setDraft(target, 'type', 'business');
        edits = edits.setDraft(target, 'email', 'third@example.org');

        assert.equal(edits.draftOf(target, 'type', 'others'), 'business');
        assert.equal(edits.draftOf(target, 'email', ''), 'third@example.org');
    });

    it('is still dropped when the form is cancelled', (): void => {
        const target = newChildTarget('email');
        const edits = EditSessions.empty().setDraft(target, 'email', 'third@example.org').endRecord(target);

        assert.equal(edits.of(target), null, 'keeping a draft is not keeping it forever');
    });
});

describe('a whole record session', (): void => {
    it('opens every writable field seeded from the state', (): void => {
        const profile = parsed();
        const edits = EditSessions.empty().beginRecord(profileTarget, recordValues(profile, profileTarget));

        assert.equal(edits.of(profileTarget)?.mode, 'record');
        assert.deepEqual(edits.of(profileTarget)?.fields, ['shortname', 'firstname', 'lastname', 'birthday', 'bio']);
        assert.equal(shown(edits, profile, 'bio'), 'Mathematician.');
    });

    it('replaces a half finished single field session rather than merging with it', (): void => {
        const profile = parsed();
        let edits = EditSessions.empty().beginField(profileTarget, 'firstname', 'Ada');
        edits = edits.setDraft(profileTarget, 'firstname', 'never submitted');
        edits = edits.applyErrors(profileTarget, { firstname: ['too long'] }, []);
        edits = edits.beginRecord(profileTarget, recordValues(profile, profileTarget));

        assert.equal(shown(edits, profile, 'firstname'), 'Ada');
        assert.deepEqual(edits.errorsOf(profileTarget, 'firstname'), []);
    });

    it('ends by discarding the whole session', (): void => {
        const profile = parsed();
        let edits = EditSessions.empty().beginRecord(profileTarget, recordValues(profile, profileTarget));
        edits = edits.applyErrors(profileTarget, { bio: ['too long'] }, ['nope']);
        edits = edits.endRecord(profileTarget);

        assert.equal(edits.of(profileTarget), null);
    });
});

describe('applying a 422', (): void => {
    it('puts a message at the field it names and keeps the draft', (): void => {
        const profile = parsed();
        let edits = EditSessions.empty().beginField(profileTarget, 'shortname', 'ada');
        edits = edits.setDraft(profileTarget, 'shortname', '');
        edits = edits.applyErrors(profileTarget, { shortname: ['Must not be empty.'] }, []);

        assert.deepEqual(edits.errorsOf(profileTarget, 'shortname'), ['Must not be empty.']);
        assert.ok(edits.isEditing(profileTarget, 'shortname'), 'the session stays open');
        assert.equal(shown(edits, profile, 'shortname'), '', 'what was typed is not discarded');
    });

    it('keeps an error that names no field on the record', (): void => {
        const edits = EditSessions.empty().applyErrors(profileTarget, {}, ['The record as a whole is wrong.']);

        assert.deepEqual(edits.generalErrorsOf(profileTarget), ['The record as a whole is wrong.']);
        assert.deepEqual(edits.errorsOf(profileTarget, 'shortname'), []);
    });

    it('creates a session for a relation operation that has no open control', (): void => {
        const edits = EditSessions.empty().applyErrors(childTarget('address', 8), {}, ['Reordering failed.']);

        assert.notEqual(edits.of(childTarget('address', 8)), null);
        assert.deepEqual(edits.generalErrorsOf(childTarget('address', 8)), ['Reordering failed.']);
        assert.deepEqual(edits.generalErrorsOf(profileTarget), [], 'and nowhere else');
    });

    it('clears the errors of fields the new answer does not mention', (): void => {
        let edits = EditSessions.empty().applyErrors(profileTarget, {
            shortname: ['Must not be empty.'],
            bio: ['Too long.'],
        }, ['and generally wrong']);
        edits = edits.applyErrors(profileTarget, { bio: ['Still too long.'] }, []);

        assert.deepEqual(edits.errorsOf(profileTarget, 'shortname'), [], 'the response is the complete answer');
        assert.deepEqual(edits.errorsOf(profileTarget, 'bio'), ['Still too long.']);
        assert.deepEqual(edits.generalErrorsOf(profileTarget), []);
    });

    it('is dropped again by clearErrors before the next attempt', (): void => {
        let edits = EditSessions.empty().beginField(profileTarget, 'shortname', 'ada');
        edits = edits.setDraft(profileTarget, 'shortname', '');
        edits = edits.applyErrors(profileTarget, { shortname: ['Must not be empty.'] }, ['nope']);
        edits = edits.clearErrors(profileTarget);

        assert.deepEqual(edits.errorsOf(profileTarget, 'shortname'), []);
        assert.deepEqual(edits.generalErrorsOf(profileTarget), []);
        assert.ok(edits.isEditing(profileTarget, 'shortname'), 'the open field survives');
        assert.equal(edits.draftOf(profileTarget, 'shortname', 'ada'), '', 'and so does the draft');
    });

    it('does not resurrect a session that clearErrors would empty', (): void => {
        const edits = EditSessions.empty().applyErrors(profileTarget, { shortname: ['x'] }, []).clearErrors(profileTarget);

        assert.equal(edits.of(profileTarget), null);
    });
});

describe('the busy flag', (): void => {
    it('keeps a session alive on its own while a request is in flight', (): void => {
        let edits = EditSessions.empty().setBusy(childTarget('address', 8), true);

        assert.ok(edits.isBusy(childTarget('address', 8)));
        assert.notEqual(edits.of(childTarget('address', 8)), null);

        edits = edits.setBusy(childTarget('address', 8), false);

        assert.equal(edits.isBusy(childTarget('address', 8)), false);
        assert.equal(edits.of(childTarget('address', 8)), null, 'and is dropped when it ends');
    });
});

describe('immutability', (): void => {
    it('never writes into the map it was given', (): void => {
        const before = EditSessions.empty().beginField(profileTarget, 'firstname', 'Ada');

        before.setDraft(profileTarget, 'firstname', 'Augusta');
        before.applyErrors(profileTarget, { firstname: ['x'] }, []);
        before.endField(profileTarget, 'firstname');
        before.endRecord(profileTarget);

        assert.equal(before.draftOf(profileTarget, 'firstname', ''), 'Ada');
        assert.deepEqual(before.errorsOf(profileTarget, 'firstname'), []);
        assert.ok(before.isEditing(profileTarget, 'firstname'));
    });
});
