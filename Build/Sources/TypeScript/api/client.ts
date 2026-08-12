/**
 * The one place that talks to the server.
 *
 * Thin on purpose: it adds the three things every write needs and hands the
 * answer straight to {@see interpretResponse}, which is where the decisions
 * are and which is testable without a network.
 *
 * - **`POST` with `Content-Type: application/json`.** Every endpoint refuses
 *   anything else — the verb because Extbase merges body parameters for `POST`
 *   only, the media type because a cross origin `<form>` cannot produce it and
 *   therefore cannot reach these endpoints without a preflight the browser will
 *   not send.
 * - **The request token in the `X-TYPO3-RequestToken` header.** The body
 *   parameter `__RequestToken` is read from `getParsedBody()`, which is `null`
 *   for a JSON request, so the header is the only transport that works here. It
 *   is read from a `data-` attribute the server rendered — the plugin is
 *   `USER_INT`, so it is never a cached token from another browser.
 * - **`credentials: 'same-origin'`**, spelled out although it is the default:
 *   without the session cookie every write is an anonymous request and answers
 *   `403`.
 *
 * A request that never produced a response is an `error` result with
 * {@see noResponseStatus}, not an exception. The caller is a UI event handler,
 * and an unhandled rejection there would leave a control disabled with no
 * explanation.
 */
import type { EndpointAction, EndpointMap } from './endpoints.js';
import type { EndpointResult } from './response.js';
import type { Payload } from './payload.js';
import { interpretResponse, noResponseStatus } from './response.js';

/**
 * The subset of `fetch` this client uses, so a test can pass a function instead
 * of a browser.
 */
export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

export class ProfileEndpointClient {
    private readonly endpoints: EndpointMap;

    private readonly requestToken: string;

    private readonly fetchImpl: FetchLike;

    /**
     * Written out rather than as constructor parameter properties, so that this
     * module — like every other module outside `component/` — contains only
     * syntax that can be erased. That is what lets a test import it into a
     * plain node process, which strips the types and runs the result.
     */
    public constructor(
        endpoints: EndpointMap,
        requestToken: string,
        fetchImpl: FetchLike = (input: string, init: RequestInit): Promise<Response> => fetch(input, init),
    ) {
        this.endpoints = endpoints;
        this.requestToken = requestToken;
        this.fetchImpl = fetchImpl;
    }

    public async send(action: EndpointAction, payload: Payload): Promise<EndpointResult> {
        let response: Response;
        try {
            response = await this.fetchImpl(this.endpoints[action], {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-TYPO3-RequestToken': this.requestToken,
                },
                body: JSON.stringify(payload),
            });
        } catch {
            return { kind: 'error', status: noResponseStatus, codes: [] };
        }

        return interpretResponse(response.status, await decode(response));
    }
}

/**
 * The decoded body, or `null` when there is none.
 *
 * Every path of the endpoint answers with JSON, failures included, but a
 * response produced by something else — a reverse proxy, an error page from a
 * misconfigured page type — is HTML, and that must end as an `error` result
 * rather than as a rejected promise.
 */
async function decode(response: Response): Promise<unknown> {
    try {
        return (await response.json()) as unknown;
    } catch {
        return null;
    }
}
