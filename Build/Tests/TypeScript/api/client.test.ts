/**
 * The one place that talks to the server, driven through its `FetchLike` seam.
 *
 * The seam is the whole reason the client is testable: `send()` takes the
 * function it calls, so a test hands it one that records the request and answers
 * a `Response` built by hand. Nothing here needs a network, a DOM or a server.
 *
 * The request token is the assertion that matters most. It travels in the
 * `X-TYPO3-RequestToken` header and not in the body, because `getParsedBody()`
 * is `null` for a JSON request and the body parameter would therefore never be
 * seen — a token in the payload looks entirely correct and produces a `403` on
 * every write.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { EndpointAction, EndpointMap } from '../../../Sources/TypeScript/api/endpoints.js';
import type { Payload } from '../../../Sources/TypeScript/api/payload.js';
import { ProfileEndpointClient } from '../../../Sources/TypeScript/api/client.js';
import { fieldPayload } from '../../../Sources/TypeScript/api/payload.js';
import { noResponseStatus } from '../../../Sources/TypeScript/api/response.js';
import { profileTarget } from '../../../Sources/TypeScript/model/recordTarget.js';
import { profileDocument } from '../profileDocument.js';

const endpoints: EndpointMap = {
    save: '/p/1?tx[action]=save&cHash=a',
    saveField: '/p/1?tx[action]=saveField&cHash=b',
    addChild: '/p/1?tx[action]=addChild&cHash=c',
    removeChild: '/p/1?tx[action]=removeChild&cHash=d',
    reorderChildren: '/p/1?tx[action]=reorderChildren&cHash=e',
    setChildVisibility: '/p/1?tx[action]=setChildVisibility&cHash=f',
};

interface RecordedRequest {
    readonly url: string;
    readonly init: RequestInit;
}

interface Recorder {
    readonly requests: RecordedRequest[];
    readonly client: ProfileEndpointClient;
}

function recording(answer: (url: string) => Promise<Response> | Response, token = 'a-request-token'): Recorder {
    const requests: RecordedRequest[] = [];
    const client = new ProfileEndpointClient(endpoints, token, async (url: string, init: RequestInit): Promise<Response> => {
        requests.push({ url, init });

        return answer(url);
    });

    return { requests, client };
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function headersOf(request: RecordedRequest): Record<string, string> {
    assert.ok(request.init.headers !== undefined, 'the client always sends headers');

    return request.init.headers as Record<string, string>;
}

function bodyOf(request: RecordedRequest): unknown {
    assert.equal(typeof request.init.body, 'string', 'the body is serialised JSON');

    return JSON.parse(request.init.body as string) as unknown;
}

describe('ProfileEndpointClient.send', (): void => {
    it('carries the request token in the X-TYPO3-RequestToken header', async (): Promise<void> => {
        const { requests, client } = recording((): Response => jsonResponse({ data: profileDocument }));
        await client.send('saveField', fieldPayload(42, profileTarget, 'firstname', 'Augusta'));

        assert.equal(requests.length, 1);
        assert.equal(headersOf(requests[0]!)['X-TYPO3-RequestToken'], 'a-request-token');
        assert.deepEqual(bodyOf(requests[0]!), { uid: 42, field: 'firstname', value: 'Augusta' },
            'and the token is not in the body, where it would never be read');
    });

    it('posts JSON with the session cookie, which is what the endpoints require', async (): Promise<void> => {
        const { requests, client } = recording((): Response => jsonResponse({ data: profileDocument }));
        await client.send('save', { uid: 42, data: {} });

        const headers = headersOf(requests[0]!);
        assert.equal(requests[0]!.init.method, 'POST');
        assert.equal(requests[0]!.init.credentials, 'same-origin');
        assert.equal(headers['Content-Type'], 'application/json');
        assert.equal(headers.Accept, 'application/json');
    });

    it('sends every action to its own server built URL', async (): Promise<void> => {
        const { requests, client } = recording((): Response => jsonResponse({ data: profileDocument }));
        const actions: readonly EndpointAction[] = [
            'save', 'saveField', 'addChild', 'removeChild', 'reorderChildren', 'setChildVisibility',
        ];
        for (const action of actions) {
            await client.send(action, { uid: 42 });
        }

        assert.deepEqual(
            requests.map((request: RecordedRequest): string => request.url),
            actions.map((action: EndpointAction): string => endpoints[action]),
        );
    });

    it('answers the interpreted result of the response', async (): Promise<void> => {
        const { client } = recording((): Response => jsonResponse({ data: profileDocument }));
        const result = await client.send('saveField', { uid: 42 });

        assert.equal(result.kind, 'success');
        assert.equal(result.kind === 'success' ? result.profile.uid : null, 42);
    });

    it('answers a validation result for a 422', async (): Promise<void> => {
        const { client } = recording((): Response => jsonResponse(
            { errors: [{ field: 'shortname', code: 1, message: 'Must not be empty.' }] },
            422,
        ));
        const result = await client.send('saveField', { uid: 42 });

        assert.equal(result.kind, 'validation');
        assert.deepEqual(result.kind === 'validation' ? result.fieldErrors : null, { shortname: ['Must not be empty.'] });
    });

    it('answers an error rather than throwing when the request never arrived', async (): Promise<void> => {
        const { client } = recording((): Response => {
            throw new TypeError('Failed to fetch');
        });
        const result = await client.send('saveField', { uid: 42 });

        assert.deepEqual(result, { kind: 'error', status: noResponseStatus, codes: [] });
    });

    it('answers an error rather than throwing when the body is not JSON', async (): Promise<void> => {
        const { client } = recording((): Response => new Response('<html>Gateway timeout</html>', { status: 504 }));
        const result = await client.send('reorderChildren', { uid: 42 });

        assert.deepEqual(result, { kind: 'error', status: 504, codes: [] });
    });

    it('sends the payload it was handed, unchanged', async (): Promise<void> => {
        const { requests, client } = recording((): Response => jsonResponse({ data: profileDocument }));
        const payload: Payload = { uid: 42, child: 'address', order: [9, 7, 8] };
        await client.send('reorderChildren', payload);

        assert.deepEqual(bodyOf(requests[0]!), { uid: 42, child: 'address', order: [9, 7, 8] });
    });
});
