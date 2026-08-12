/**
 * The request bodies of the six writing endpoints.
 *
 * Pure functions over a target and a value, kept out of the components on
 * purpose: what a payload contains is contract with the server, it is the thing
 * most likely to be got subtly wrong — a `childUid` on a profile save, a
 * partial order on a reorder — and it is trivially testable in isolation.
 *
 * Three properties hold for all of them:
 *
 * - **`uid` is always the profile uid.** It filters the set the session owns
 *   and never seeds a lookup, so sending it is not an authorisation statement.
 * - **A child payload carries `child` and `childUid`**, a profile payload
 *   carries neither. The server reads `child` as a closed set and answers `400`
 *   for anything else.
 * - **Only what is being written is sent.** A single field save carries exactly
 *   one field, which is what makes it a partial save rather than a full one
 *   that happens to change one value.
 */
import type { ChildType } from '../model/types.js';
import type { RecordTarget } from '../model/recordTarget.js';

export type Payload = Record<string, unknown>;

/**
 * The payload of `saveField`: one named field of one record.
 *
 * The value is sent as a string, or as `null` for a cleared text field — the
 * two things `ProfileAjaxController::requiredFieldValue()` accepts. The
 * component never sends `null` today, because a control always yields a string;
 * the type says what the endpoint takes rather than what one caller happens to
 * pass.
 */
export function fieldPayload(
    profileUid: number,
    target: RecordTarget,
    field: string,
    value: string | null,
): Payload {
    return { ...recordIdentity(profileUid, target), field, value };
}

/**
 * The payload of `save`: every writable field of one record at once.
 */
export function recordPayload(
    profileUid: number,
    target: RecordTarget,
    data: Readonly<Record<string, string>>,
): Payload {
    return { ...recordIdentity(profileUid, target), data: { ...data } };
}

/**
 * The payload of `addChild`. The new record sorts last, which the server
 * decides — nothing about a position is sent.
 */
export function addChildPayload(
    profileUid: number,
    child: ChildType,
    data: Readonly<Record<string, string>>,
): Payload {
    return { uid: profileUid, child, data: { ...data } };
}

export function removeChildPayload(profileUid: number, child: ChildType, childUid: number): Payload {
    return { uid: profileUid, child, childUid };
}

/**
 * The payload of `reorderChildren`, which carries the **whole** intended order.
 *
 * Not a delta and not a partial list: the submitted order replaces the
 * collection, and the endpoint deletes every record it omits. It refuses a list
 * whose length or membership does not match, before anything is written — see
 * `movedChildOrder()`, which is what produces the list passed in here.
 */
export function reorderPayload(profileUid: number, child: ChildType, order: readonly number[]): Payload {
    return { uid: profileUid, child, order: [...order] };
}

/**
 * The payload of `setChildVisibility`, which takes the wanted state and not a
 * toggle — the endpoint is idempotent on purpose, so a client whose idea of the
 * current state is stale still ends up where the user asked for.
 */
export function visibilityPayload(
    profileUid: number,
    child: ChildType,
    childUid: number,
    hidden: boolean,
): Payload {
    return { uid: profileUid, child, childUid, hidden };
}

/**
 * The identity part every `save` and `saveField` payload starts with.
 *
 * A target for a child that does not exist yet cannot appear here — it has no
 * `childUid`, and the endpoint that creates it is `addChild`. Sending
 * `childUid: null` would be answered with `400`, so the payload is built
 * without one and fails the endpoint's "missing" check instead of its "wrong
 * type" check. Neither is reachable from the component, which never submits a
 * new child through this path.
 */
function recordIdentity(profileUid: number, target: RecordTarget): Payload {
    if (target.child === null) {
        return { uid: profileUid };
    }
    if (target.childUid === null) {
        return { uid: profileUid, child: target.child };
    }

    return { uid: profileUid, child: target.child, childUid: target.childUid };
}
