/**
 * What a write actually sends.
 *
 * The property that matters is a *negative* one and therefore has to be asserted
 * as a whole object rather than key by key: a single field save carries exactly
 * the field that was submitted and nothing else. A second field riding along
 * would make every inline edit a full overwrite, silently discarding whatever
 * another session changed in the meantime — and it would look completely normal
 * in the browser, because the client is sending values it believes are current.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import {
    addChildPayload,
    fieldPayload,
    imageUidPart,
    imageUploadBody,
    imageUploadPart,
    recordPayload,
    removeChildPayload,
    removeImagePayload,
    reorderPayload,
    visibilityPayload,
} from '../../../Sources/TypeScript/frontend/api/payload.js';
import { childTarget, newChildTarget, profileTarget } from '../../../Sources/TypeScript/frontend/model/recordTarget.js';

describe('fieldPayload', (): void => {
    it('sends the submitted field and nothing else', (): void => {
        assert.deepEqual(
            fieldPayload(42, profileTarget, 'firstname', 'Augusta'),
            { uid: 42, field: 'firstname', value: 'Augusta' },
        );
    });

    it('names the child a child field belongs to', (): void => {
        assert.deepEqual(
            fieldPayload(42, childTarget('address', 8), 'line1', 'Analytical Engine'),
            { uid: 42, child: 'address', childUid: 8, field: 'line1', value: 'Analytical Engine' },
        );
    });

    it('keeps a cleared field as an explicit null rather than dropping it', (): void => {
        const payload = fieldPayload(42, profileTarget, 'bio', null);

        assert.deepEqual(payload, { uid: 42, field: 'bio', value: null });
        assert.ok('value' in payload);
    });

    it('omits the child uid of a child that does not exist yet', (): void => {
        assert.deepEqual(
            fieldPayload(42, newChildTarget('email'), 'email', 'ada@example.org'),
            { uid: 42, child: 'email', field: 'email', value: 'ada@example.org' },
        );
    });
});

describe('recordPayload', (): void => {
    it('sends the whole record under data, unlike a single field save', (): void => {
        assert.deepEqual(
            recordPayload(42, profileTarget, { firstname: 'Augusta', lastname: 'King' }),
            { uid: 42, data: { firstname: 'Augusta', lastname: 'King' } },
        );
    });

    it('copies the data so a later change to the source does not reach the wire', (): void => {
        const data: Record<string, string> = { firstname: 'Augusta' };
        const payload = recordPayload(42, profileTarget, data);
        data.firstname = 'Ada';

        assert.deepEqual(payload, { uid: 42, data: { firstname: 'Augusta' } });
    });
});

describe('the image bodies', (): void => {
    it('names the file part the way Extbase looks it up, or it is never found', (): void => {
        assert.equal(imageUploadPart, 'tx_modernextbasefrontendedit_ajax[profile][image]');
        assert.equal(imageUidPart, 'tx_modernextbasefrontendedit_ajax[uid]');
    });

    it('sends the file and the profile uid, and nothing else', (): void => {
        const file = new File(['not really a jpeg'], 'holiday.jpg', { type: 'image/jpeg' });
        const body = imageUploadBody(42, file);
        const part = body.get(imageUploadPart);

        assert.deepEqual([...body.keys()], [imageUidPart, imageUploadPart]);
        assert.equal(body.get(imageUidPart), '42', 'every part of a multipart body is text');
        assert.ok(part instanceof File);
        assert.equal(part.size, file.size, 'the file goes on the wire as it is, not base64 encoded');
        assert.equal(part.type, 'image/jpeg');
    });

    it('keeps the client filename, which is the basename the server suffixes', (): void => {
        const body = imageUploadBody(42, new File([''], 'holiday.jpg', { type: 'image/jpeg' }));
        const part = body.get(imageUploadPart);

        assert.ok(part instanceof File);
        assert.equal(part.name, 'holiday.jpg', 'without it a browser sends "blob"');
    });

    it('removes the image by profile uid alone, never by a client supplied file uid', (): void => {
        assert.deepEqual(removeImagePayload(42), { uid: 42 });
    });
});

describe('the relation payloads', (): void => {
    it('sends no position when adding, because the server decides it', (): void => {
        assert.deepEqual(
            addChildPayload(42, 'address', { type: 'others', line1: 'Somewhere', line2: '' }),
            { uid: 42, child: 'address', data: { type: 'others', line1: 'Somewhere', line2: '' } },
        );
    });

    it('addresses a removal by profile uid, child type and child uid', (): void => {
        assert.deepEqual(removeChildPayload(42, 'email', 21), { uid: 42, child: 'email', childUid: 21 });
    });

    it('sends the wanted visibility rather than a toggle', (): void => {
        assert.deepEqual(
            visibilityPayload(42, 'address', 8, true),
            { uid: 42, child: 'address', childUid: 8, hidden: true },
        );
    });

    it('copies the order so the caller cannot mutate a sent payload', (): void => {
        const order = [9, 7, 8];
        const payload = reorderPayload(42, 'address', order);
        order[0] = 99;

        assert.deepEqual(payload, { uid: 42, child: 'address', order: [9, 7, 8] });
    });
});
