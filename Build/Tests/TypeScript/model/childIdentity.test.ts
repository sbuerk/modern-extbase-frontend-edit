/**
 * Which field of which record carries the identity a heading shows.
 *
 * The rule worth pinning is not that `line1` is read for an address — that is
 * one line of code — but that the *announced* child type decides it. The two
 * record shapes share `uid`, `type` and `hidden` and have no discriminant, so a
 * caller that asked for an e-mail address and was handed an address is a case
 * the function has to answer for rather than crash on: this feeds a heading, and
 * a heading is the wrong place for a mismatch between two server responses to
 * surface.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { AddressRecord, EmailRecord } from '../../../Sources/TypeScript/frontend/model/types.js';
import { childIdentity } from '../../../Sources/TypeScript/frontend/model/childIdentity.js';

const address = (changes: Partial<AddressRecord> = {}): AddressRecord => ({
    uid: 2,
    type: 'work',
    line1: 'Difference Engine Road 1',
    line2: 'Second line of the first address',
    hidden: false,
    ...changes,
});

const email = (changes: Partial<EmailRecord> = {}): EmailRecord => ({
    uid: 2,
    type: 'business',
    email: 'ada@example.org',
    hidden: false,
    ...changes,
});

describe('childIdentity', (): void => {
    it('reads the first line of an address and the address of an e-mail', (): void => {
        assert.deepEqual(childIdentity('address', address()), {
            type: 'work',
            detail: 'Difference Engine Road 1',
        });
        assert.deepEqual(childIdentity('email', email()), {
            type: 'business',
            detail: 'ada@example.org',
        });
    });

    it('never reads the second address line', (): void => {
        // line2 is the field most likely to be reached for by mistake, and it is
        // the wrong one: two addresses in the same street differ in their first
        // line far more often than in their second.
        assert.equal(
            childIdentity('address', address({ line1: '', line2: 'still filled' })).detail,
            '',
        );
    });

    it('trims, so that whitespace is not an identity', (): void => {
        assert.deepEqual(childIdentity('address', address({ type: ' work ', line1: '  Road 1  ' })), {
            type: 'work',
            detail: 'Road 1',
        });
        assert.equal(childIdentity('address', address({ line1: '   ' })).detail, '');
    });

    it('answers for a record that is not the type it was announced as', (): void => {
        // An address handed over as an e-mail address has no `email` property at
        // all. The identity is empty rather than `undefined`, and nothing throws.
        assert.deepEqual(childIdentity('email', address() as unknown as EmailRecord), {
            type: 'work',
            detail: '',
        });
        assert.deepEqual(childIdentity('address', email() as unknown as AddressRecord), {
            type: 'business',
            detail: '',
        });
    });

    it('reports an empty identity rather than inventing one', (): void => {
        // The heading is then drawn from what is left, which is the caller's
        // decision - this says only that nothing is fabricated here.
        assert.deepEqual(childIdentity('address', address({ type: '', line1: '' })), {
            type: '',
            detail: '',
        });
    });
});
