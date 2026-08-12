/**
 * The endpoint URLs, one per action.
 *
 * ## Why this is a map and not a single URL
 *
 * The Extbase action is part of the query string —
 * `tx_modernextbasefrontendedit_ajax[action]=saveField` — and therefore part of
 * the cHash, which `PageArgumentValidator` answers a `404` for when it is
 * missing or wrong. A cHash cannot be computed in a browser, so a client cannot
 * assemble an endpoint URL from a base and an action name. Every URL is built
 * server side with the Extbase `UriBuilder` and handed over ready to use.
 *
 * The consequence for the Fluid layer is one attribute carrying an object, not
 * one attribute carrying a string.
 */
export type EndpointAction =
    | 'save'
    | 'saveField'
    | 'addChild'
    | 'removeChild'
    | 'reorderChildren'
    | 'setChildVisibility'
    | 'uploadImage'
    | 'removeImage';

/**
 * The actions the component calls.
 *
 * `read` is deliberately not among them: the initial state is rendered into the
 * markup by the same request that rendered the profile, and every write answers
 * with the whole aggregate, so nothing the component does ever needs to read
 * separately.
 *
 * `uploadImage` is the one entry whose request is not a JSON body — it is a
 * `multipart/form-data` POST. That changes what the client sends and nothing
 * else: the URL is built the same way, the request token travels in the same
 * header, and the answer is the same document.
 * → {@see ../api/payload.ts} for the body, {@see ../api/client.ts} for the
 * content type.
 */
export const endpointActions: readonly EndpointAction[] = [
    'save',
    'saveField',
    'addChild',
    'removeChild',
    'reorderChildren',
    'setChildVisibility',
    'uploadImage',
    'removeImage',
];

export type EndpointMap = Readonly<Record<EndpointAction, string>>;

/**
 * Reads an unknown value as the endpoint map, or answers `null`.
 *
 * **All or nothing.** A map missing one URL is refused rather than accepted
 * with that one affordance disabled: an editing surface where a button silently
 * does nothing is worse than one that never appeared, and the server rendered
 * profile is still on the page either way.
 */
export function parseEndpoints(value: unknown): EndpointMap | null {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return null;
    }
    const source = value as Record<string, unknown>;
    const endpoints: Partial<Record<EndpointAction, string>> = {};
    for (const action of endpointActions) {
        const url = source[action];
        if (typeof url !== 'string' || url === '') {
            return null;
        }
        endpoints[action] = url;
    }

    return endpoints as EndpointMap;
}
