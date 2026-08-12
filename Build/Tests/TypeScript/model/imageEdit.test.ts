/**
 * The four decisions the image surface makes before it draws anything.
 *
 * The one that carries a rule of the design is {@see uploadFailureMessages}:
 * nothing is moved into storage when an upload is rejected, so the surface has
 * to say that the file has to be picked again. Asserting that the notice is
 * present is asserting that the user is not left believing the file is still
 * held — which is what a control that kept the filename would say.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { ProfileImageRecord } from '../../../Sources/TypeScript/model/types.js';
import {
    imageAlternative,
    isDisplayable,
    uploadFailureMessages,
} from '../../../Sources/TypeScript/model/imageEdit.js';
import { parseProfileImage } from '../../../Sources/TypeScript/model/profileRecord.js';

function image(changes: Readonly<Record<string, unknown>> = {}): ProfileImageRecord {
    const parsed = parseProfileImage({
        uid: 5,
        fileUid: 12,
        publicUrl: '/fileadmin/user_upload/profiles/portrait-9f2c1ab4c07d3e51.jpg',
        alternative: '',
        title: '',
        ...changes,
    });
    assert.notEqual(parsed, null, 'the fixture has to parse');

    return parsed as ProfileImageRecord;
}

describe('isDisplayable', (): void => {
    it('separates "there is nothing to show" from "there is nothing stored"', (): void => {
        assert.equal(isDisplayable(image()), true);
        assert.equal(isDisplayable(null), false, 'no image at all');
        assert.equal(
            isDisplayable(image({ publicUrl: null })),
            false,
            'a reference whose file is gone shows nothing, and is still removable',
        );
    });
});

describe('imageAlternative', (): void => {
    it('prefers the alternative text stored on the reference, like the read partial', (): void => {
        assert.equal(
            imageAlternative(image({ alternative: 'Ada at her desk' }), 'Portrait of %s', 'Ada Lovelace'),
            'Ada at her desk',
        );
    });

    it('falls back to the translated sentence, with the current name substituted', (): void => {
        assert.equal(imageAlternative(image(), 'Portrait of %s', 'Ada Lovelace'), 'Portrait of Ada Lovelace');
        assert.equal(
            imageAlternative(image(), 'Portrait of %s', 'Augusta King'),
            'Portrait of Augusta King',
            'a saved name change reaches the alternative text',
        );
    });

    it('answers a sentence without a placeholder unchanged', (): void => {
        assert.equal(imageAlternative(image(), 'Profile image', 'Ada Lovelace'), 'Profile image');
    });
});

describe('uploadFailureMessages', (): void => {
    it('appends the notice that the file was not stored, after the reason it was not', (): void => {
        assert.deepEqual(
            uploadFailureMessages(['The file is too large.'], 'The file was not stored. Please choose it again.'),
            ['The file is too large.', 'The file was not stored. Please choose it again.'],
        );
    });

    it('shows the notice on its own for a failure the server did not explain', (): void => {
        assert.deepEqual(
            uploadFailureMessages([], 'The file was not stored. Please choose it again.'),
            ['The file was not stored. Please choose it again.'],
            'a 403 or a request that never arrived loses the file just as thoroughly',
        );
    });

    it('adds nothing when there was no failure', (): void => {
        assert.deepEqual(uploadFailureMessages([], ''), []);
    });

    it('adds nothing but the messages when the notice is not translated', (): void => {
        assert.deepEqual(uploadFailureMessages(['The file is too large.'], ''), ['The file is too large.']);
    });

    it('does not repeat a notice the server already sent', (): void => {
        assert.deepEqual(uploadFailureMessages(['Pick it again.'], 'Pick it again.'), ['Pick it again.']);
    });

    it('does not write into the messages it was given', (): void => {
        const messages = ['The file is too large.'];
        uploadFailureMessages(messages, 'Pick it again.');

        assert.deepEqual(messages, ['The file is too large.']);
    });
});
