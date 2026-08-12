/**
 * Turning an answer into one of three outcomes.
 *
 * The interesting cases are the ones where a plausible implementation loses
 * information instead of degrading:
 *
 * - A `422` entry that names no field is an error about the record. Dropping it
 *   because it does not fit a field keyed map would leave a rejected save with a
 *   form that looks untouched and no explanation anywhere.
 * - A `200` whose `data` the parser rejects is not a success. Treating it as one
 *   would render a record that was never received.
 * - A `422` that names nothing at all is not a validation outcome either, for
 *   the same reason: there is nothing to show at any field.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import {
    errorCodesFrom,
    interpretResponse,
    noResponseStatus,
    validationErrorsFrom,
} from '../../../Sources/TypeScript/api/response.js';
import { profileDocument, profileDocumentWith } from '../profileDocument.js';

describe('interpretResponse on a 200', (): void => {
    it('answers the state the server persisted, not what was sent', (): void => {
        const result = interpretResponse(200, { data: profileDocumentWith({ shortname: 'ada-lovelace' }) });

        assert.equal(result.kind, 'success');
        assert.equal(result.kind === 'success' ? result.profile.shortname : null, 'ada-lovelace');
    });

    it('degrades to an error when the data cannot be parsed', (): void => {
        for (const body of [
            { data: null },
            { data: 'nope' },
            { data: [] },
            { data: { uid: 0 } },
            { data: profileDocumentWith({ uid: 'x' }) },
            {},
            null,
            'not an envelope',
        ]) {
            const result = interpretResponse(200, body);

            assert.equal(result.kind, 'error', `an unparseable body must not be a success: ${JSON.stringify(body)}`);
        }
    });

    it('keeps the codes of an unparseable 200, so the failure is greppable', (): void => {
        assert.deepEqual(
            interpretResponse(200, { errors: [{ code: 1771234567, message: 'internal' }] }),
            { kind: 'error', status: 200, codes: [1771234567] },
        );
    });
});

describe('interpretResponse on a 422', (): void => {
    it('keys the messages by the field they name', (): void => {
        const result = interpretResponse(422, {
            errors: [
                { field: 'shortname', code: 1, message: 'Must not be empty.' },
                { field: 'shortname', code: 2, message: 'Must be shorter.' },
                { field: 'birthday', code: 3, message: 'Not a date.' },
            ],
        });

        assert.equal(result.kind, 'validation');
        assert.deepEqual(result.kind === 'validation' ? result.fieldErrors : null, {
            shortname: ['Must not be empty.', 'Must be shorter.'],
            birthday: ['Not a date.'],
        });
        assert.deepEqual(result.kind === 'validation' ? result.generalErrors : null, []);
    });

    it('keeps an error that names no field instead of swallowing it', (): void => {
        const result = interpretResponse(422, {
            errors: [{ field: null, code: 1, message: 'The record as a whole is wrong.' }],
        });

        assert.equal(result.kind, 'validation', 'a general error alone is still a validation outcome');
        assert.deepEqual(result.kind === 'validation' ? result.generalErrors : null, ['The record as a whole is wrong.']);
        assert.deepEqual(result.kind === 'validation' ? result.fieldErrors : null, {});
    });

    it('degrades to an error when it names nothing at all', (): void => {
        for (const body of [
            { errors: [] },
            { errors: [{ code: 1771234567, message: '' }] },
            { errors: [{ field: 'shortname', code: 1 }] },
            { errors: 'nope' },
            {},
            null,
        ]) {
            const result = interpretResponse(422, body);

            assert.equal(result.kind, 'error', `a 422 with no message must not be a validation outcome: ${JSON.stringify(body)}`);
            assert.equal(result.kind === 'error' ? result.status : null, 422);
        }
    });
});

describe('interpretResponse on everything else', (): void => {
    it('answers an error carrying the status and the codes', (): void => {
        assert.deepEqual(
            interpretResponse(403, { errors: [{ code: 1771234567, message: 'Not yours.' }] }),
            { kind: 'error', status: 403, codes: [1771234567] },
        );
        assert.deepEqual(interpretResponse(500, '<html>'), { kind: 'error', status: 500, codes: [] });
        assert.deepEqual(interpretResponse(noResponseStatus, null), { kind: 'error', status: 0, codes: [] });
    });

    it('never turns a non-200 body into a success, even when it carries data', (): void => {
        assert.equal(interpretResponse(409, { data: profileDocument }).kind, 'error');
    });
});

describe('validationErrorsFrom', (): void => {
    it('treats a missing, empty or non-string field name as general', (): void => {
        const errors = validationErrorsFrom({
            errors: [
                { code: 1, message: 'no field key' },
                { field: null, code: 2, message: 'explicit null' },
                { field: '', code: 3, message: 'empty name' },
                { field: 17, code: 4, message: 'not a string' },
            ],
        });

        assert.deepEqual(errors.fieldErrors, {});
        assert.deepEqual(errors.generalErrors, ['no field key', 'explicit null', 'empty name', 'not a string']);
    });

    it('skips an entry without a usable message', (): void => {
        const errors = validationErrorsFrom({
            errors: [
                { field: 'shortname', code: 1, message: '' },
                { field: 'shortname', code: 2 },
                { field: 'shortname', code: 3, message: 17 },
                { field: 'shortname', code: 4, message: 'the only one' },
                'not an entry',
            ],
        });

        assert.deepEqual(errors.fieldErrors, { shortname: ['the only one'] });
        assert.deepEqual(errors.generalErrors, []);
    });
});

describe('errorCodesFrom', (): void => {
    it('collects the codes of every entry that has one', (): void => {
        assert.deepEqual(
            errorCodesFrom({ errors: [{ code: 1 }, { message: 'no code' }, { code: '2' }, { code: 3 }] }),
            [1, 3],
        );
    });

    it('answers an empty list for a body that is not the envelope', (): void => {
        assert.deepEqual(errorCodesFrom(null), []);
        assert.deepEqual(errorCodesFrom('<html>'), []);
        assert.deepEqual(errorCodesFrom({ errors: {} }), []);
    });
});
