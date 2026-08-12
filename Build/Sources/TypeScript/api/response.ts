/**
 * Turning a status code and a decoded body into something the UI can act on.
 *
 * The endpoints answer with one envelope on every path — `{"data": …}` on
 * success, `{"errors": [{field?, code, message}]}` on every failure — and the
 * status code is what says which. Three outcomes are distinguishable, and the
 * distinction is not cosmetic:
 *
 * - **`success`** carries the persisted state of the whole aggregate. It is
 *   rendered as it arrives; a client that renders its own optimistic guess
 *   drifts away from the database on the first value the server normalises.
 * - **`validation`** is the `422`, whose entries are keyed by field name. They
 *   are shown *at the field*, the session stays open and the drafts stay put:
 *   a failed save must neither look like a success nor discard what was typed.
 *   Its messages are already translated — `AbstractValidator::translateErrorMessage()`
 *   resolved them from the rule set's XLIFF keys — so they are displayed as
 *   they arrive.
 * - **`error`** is everything else: `400`, `403`, `404`, `405`, `409`, a `5xx`,
 *   a body that is not the expected envelope, or a request that never arrived.
 *   Its `message` is written for a developer and is deliberately **not**
 *   displayed; the component shows a translated sentence of its own and keeps
 *   the code for whoever reads the DOM.
 */
import type { ProfileRecord } from '../model/types.js';
import { parseProfileRecord } from '../model/profileRecord.js';

export interface ValidationErrors {
    /**
     * The messages of the `422` entries that named a field, keyed by that name.
     */
    readonly fieldErrors: Readonly<Record<string, string[]>>;
    /**
     * The messages of the entries whose `field` was `null` — an error attached
     * to the record rather than to one of its properties.
     */
    readonly generalErrors: readonly string[];
}

export type EndpointResult =
    | { readonly kind: 'success'; readonly profile: ProfileRecord }
    | ({ readonly kind: 'validation' } & ValidationErrors)
    | { readonly kind: 'error'; readonly status: number; readonly codes: readonly number[] };

/**
 * The status the component uses for a request that never produced a response.
 *
 * `0` is not a status code, which is the point: it is distinguishable from
 * every answer the server can give, and it is what a network failure or an
 * aborted request looks like.
 */
export const noResponseStatus = 0;

export function interpretResponse(status: number, body: unknown): EndpointResult {
    if (status === 200) {
        const profile = parseProfileRecord(dataOf(body));

        return profile === null
            ? { kind: 'error', status, codes: errorCodesFrom(body) }
            : { kind: 'success', profile };
    }

    if (status === 422) {
        const errors = validationErrorsFrom(body);

        // A 422 that names nothing at all would leave the user with an
        // unchanged form and no explanation, so it degrades to the generic
        // failure the component does have a sentence for.
        return Object.keys(errors.fieldErrors).length === 0 && errors.generalErrors.length === 0
            ? { kind: 'error', status, codes: errorCodesFrom(body) }
            : { kind: 'validation', ...errors };
    }

    return { kind: 'error', status, codes: errorCodesFrom(body) };
}

/**
 * Splits the `errors` array of a `422` into the field keyed part and the rest.
 *
 * Several entries may name the same field — the rule sets do run more than one
 * validator per property — so the value is a list rather than a single message.
 */
export function validationErrorsFrom(body: unknown): ValidationErrors {
    const fieldErrors: Record<string, string[]> = {};
    const generalErrors: string[] = [];

    for (const entry of errorEntriesOf(body)) {
        const message = entry.message;
        if (typeof message !== 'string' || message === '') {
            continue;
        }
        const field = entry.field;
        if (typeof field !== 'string' || field === '') {
            generalErrors.push(message);
            continue;
        }
        (fieldErrors[field] ??= []).push(message);
    }

    return { fieldErrors, generalErrors };
}

/**
 * The `code` of every error entry, for a failure that is not shown as text.
 *
 * They are TYPO3 style unix timestamp exception codes, so a code a user reports
 * is greppable to one line of PHP.
 */
export function errorCodesFrom(body: unknown): number[] {
    const codes: number[] = [];
    for (const entry of errorEntriesOf(body)) {
        if (typeof entry.code === 'number') {
            codes.push(entry.code);
        }
    }

    return codes;
}

function errorEntriesOf(body: unknown): Record<string, unknown>[] {
    if (!isObject(body) || !Array.isArray(body.errors)) {
        return [];
    }

    return body.errors.filter(isObject);
}

function dataOf(body: unknown): unknown {
    return isObject(body) ? body.data : null;
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
