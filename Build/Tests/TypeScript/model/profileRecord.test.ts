/**
 * The last server known state: reading it, and computing a reorder from it.
 *
 * Three of the design's rules are decided in this module, and each is asserted
 * for the failure it prevents rather than for its happy path:
 *
 * - A document the parser cannot make sense of answers `null`, so a caller has
 *   no way to end up with half a record applied.
 * - `fieldValue()` reads the *current* state, which is what makes cancel restore
 *   the last successful save instead of the page load.
 * - `movedChildOrder()` answers a full permutation or the unchanged order, never
 *   a list the reorder endpoint would refuse — it deletes every record the
 *   submitted order omits.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { ProfileRecord } from '../../../Sources/TypeScript/model/types.js';
import {
    childUids,
    fieldValue,
    movedChildOrder,
    parseProfileRecord,
    recordOf,
    recordValues,
} from '../../../Sources/TypeScript/model/profileRecord.js';
import { childTarget, newChildTarget, profileTarget } from '../../../Sources/TypeScript/model/recordTarget.js';
import { profileDocument, profileDocumentWith } from '../profileDocument.js';

function parsed(document: unknown = profileDocument): ProfileRecord {
    const profile = parseProfileRecord(document);
    assert.notEqual(profile, null, 'the fixture document has to parse');

    return profile as ProfileRecord;
}

describe('parseProfileRecord', (): void => {
    it('reads the document the endpoints answer with', (): void => {
        const profile = parsed();

        assert.equal(profile.uid, 42);
        assert.equal(profile.firstname, 'Ada');
        assert.equal(profile.hidden, false);
        assert.deepEqual(childUids(profile, 'address'), [7, 8, 9]);
        assert.deepEqual(childUids(profile, 'email'), [21]);
    });

    it('rejects a document that is not an object', (): void => {
        assert.equal(parseProfileRecord(null), null);
        assert.equal(parseProfileRecord('42'), null);
        assert.equal(parseProfileRecord(42), null);
        assert.equal(parseProfileRecord([profileDocument]), null);
        assert.equal(parseProfileRecord(undefined), null);
    });

    it('rejects a document without a usable uid, so nothing is half applied', (): void => {
        assert.equal(parseProfileRecord(profileDocumentWith({ uid: 0 })), null);
        assert.equal(parseProfileRecord(profileDocumentWith({ uid: -1 })), null);
        assert.equal(parseProfileRecord(profileDocumentWith({ uid: 1.5 })), null);
        assert.equal(parseProfileRecord(profileDocumentWith({ uid: '42' })), null);
        assert.equal(parseProfileRecord(profileDocumentWith({ uid: null })), null);
    });

    it('normalises a missing or mistyped scalar to the empty value of its type', (): void => {
        const profile = parsed(profileDocumentWith({ bio: undefined, birthday: 17, hidden: 'yes' }));

        assert.equal(profile.bio, '');
        assert.equal(profile.birthday, '');
        assert.equal(profile.hidden, false, 'only a literal true hides a record');
    });

    it('drops an unusable child and keeps the rest of the collection', (): void => {
        const profile = parsed(profileDocumentWith({
            addresses: [
                { uid: 7, type: 'home', line1: 'Ockham Park', line2: '', hidden: false },
                { uid: 0, type: 'work', line1: 'nope', line2: '', hidden: false },
                'not a record',
            ],
            emails: 'not an array',
        }));

        assert.deepEqual(childUids(profile, 'address'), [7]);
        assert.deepEqual(childUids(profile, 'email'), []);
    });
});

describe('fieldValue', (): void => {
    it('answers the value the state currently holds', (): void => {
        const profile = parsed();

        assert.equal(fieldValue(profile, profileTarget, 'firstname'), 'Ada');
        assert.equal(fieldValue(profile, childTarget('address', 8), 'line1'), 'Analytical Engine');
        assert.equal(fieldValue(profile, childTarget('email', 21), 'email'), 'ada@example.org');
    });

    it('answers the value of the newer state after a save replaced it', (): void => {
        const beforeSave = parsed();
        const afterSave = parsed(profileDocumentWith({ shortname: 'ada-lovelace' }));

        assert.equal(fieldValue(beforeSave, profileTarget, 'shortname'), 'ada');
        assert.equal(
            fieldValue(afterSave, profileTarget, 'shortname'),
            'ada-lovelace',
            'cancel restores the last successful save, not the value the page was loaded with',
        );
    });

    it('answers an empty string for a record or a field that is not there', (): void => {
        const profile = parsed();

        assert.equal(fieldValue(profile, childTarget('address', 4711), 'line1'), '');
        assert.equal(fieldValue(profile, newChildTarget('address'), 'line1'), '');
        assert.equal(fieldValue(profile, profileTarget, 'nosuchfield'), '');
        assert.equal(fieldValue(profile, profileTarget, 'hidden'), '', 'a boolean is not an editable value');
    });
});

describe('recordOf and recordValues', (): void => {
    it('resolves a target onto the record it names', (): void => {
        const profile = parsed();

        assert.equal(recordOf(profile, profileTarget), profile);
        assert.deepEqual(recordOf(profile, childTarget('address', 9)), {
            uid: 9, type: 'others', line1: 'Somewhere', line2: '', hidden: false,
        });
        assert.equal(recordOf(profile, childTarget('address', 4711)), null);
        assert.equal(recordOf(profile, newChildTarget('address')), null);
    });

    it('collects exactly the writable fields of a record', (): void => {
        const profile = parsed();

        assert.deepEqual(recordValues(profile, profileTarget), {
            shortname: 'ada',
            firstname: 'Ada',
            lastname: 'Lovelace',
            birthday: '1815-12-10',
            bio: 'Mathematician.',
        });
        assert.deepEqual(recordValues(profile, childTarget('email', 21)), {
            type: 'private',
            email: 'ada@example.org',
        });
    });
});

describe('movedChildOrder', (): void => {
    it('answers the whole resulting order when moving towards the front', (): void => {
        assert.deepEqual(movedChildOrder(parsed(), 'address', 9, -1), [7, 9, 8]);
    });

    it('answers the whole resulting order when moving towards the back', (): void => {
        assert.deepEqual(movedChildOrder(parsed(), 'address', 7, 1), [8, 7, 9]);
    });

    it('answers the unchanged order for a move that would leave the collection', (): void => {
        const profile = parsed();

        assert.deepEqual(movedChildOrder(profile, 'address', 7, -1), [7, 8, 9], 'the first one upwards');
        assert.deepEqual(movedChildOrder(profile, 'address', 9, 1), [7, 8, 9], 'the last one downwards');
        assert.deepEqual(movedChildOrder(profile, 'address', 7, -3), [7, 8, 9]);
        assert.deepEqual(movedChildOrder(profile, 'address', 7, 3), [7, 8, 9]);
    });

    it('answers the unchanged order for a uid that is not a member', (): void => {
        assert.deepEqual(movedChildOrder(parsed(), 'address', 4711, -1), [7, 8, 9]);
        assert.deepEqual(movedChildOrder(parsed(), 'address', 21, -1), [7, 8, 9], 'a uid of the other collection');
    });

    it('always answers a permutation of the collection, never a partial list', (): void => {
        const profile = parsed();
        const members = [7, 8, 9];

        for (const uid of [...members, 4711]) {
            for (const offset of [-3, -2, -1, 0, 1, 2, 3]) {
                const order = movedChildOrder(profile, 'address', uid, offset);

                assert.deepEqual(
                    [...order].sort((a: number, b: number): number => a - b),
                    members,
                    `moving ${uid} by ${offset} must keep every member exactly once`,
                );
            }
        }
    });

    it('does not touch the state it computed from', (): void => {
        const profile = parsed();
        movedChildOrder(profile, 'address', 7, 1);

        assert.deepEqual(childUids(profile, 'address'), [7, 8, 9]);
    });
});
