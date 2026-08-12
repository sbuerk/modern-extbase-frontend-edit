/**
 * Reading the endpoint map out of the markup, which is all or nothing.
 *
 * A map missing one URL is refused rather than accepted with one affordance
 * disabled: the component then does not enhance at all and the server rendered
 * profile stays on the page, which is a worse looking but honest outcome than an
 * editing surface with a button that silently does nothing.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import { endpointActions, parseEndpoints } from '../../../Sources/TypeScript/api/endpoints.js';

const complete: Record<string, unknown> = {
    save: '/save',
    saveField: '/save-field',
    addChild: '/add-child',
    removeChild: '/remove-child',
    reorderChildren: '/reorder-children',
    setChildVisibility: '/set-child-visibility',
};

describe('parseEndpoints', (): void => {
    it('reads a complete map', (): void => {
        assert.deepEqual(parseEndpoints({ ...complete }), complete);
    });

    it('ignores keys that are not endpoint actions', (): void => {
        assert.deepEqual(parseEndpoints({ ...complete, read: '/read', '': 'x' }), complete);
    });

    it('refuses a map that is missing or empties any single URL', (): void => {
        for (const action of endpointActions) {
            assert.equal(parseEndpoints({ ...complete, [action]: undefined }), null, `missing ${action}`);
            assert.equal(parseEndpoints({ ...complete, [action]: '' }), null, `empty ${action}`);
            assert.equal(parseEndpoints({ ...complete, [action]: 17 }), null, `non-string ${action}`);
        }
    });

    it('refuses a value that is not an object at all', (): void => {
        assert.equal(parseEndpoints(null), null);
        assert.equal(parseEndpoints(undefined), null);
        assert.equal(parseEndpoints('{}'), null);
        assert.equal(parseEndpoints([complete]), null);
    });
});
