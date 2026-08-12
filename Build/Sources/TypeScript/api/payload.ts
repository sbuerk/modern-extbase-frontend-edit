/**
 * The request bodies of the writing endpoints.
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
 *
 * Seven of the eight are a JSON object. The eighth, {@see imageUploadBody}, is a
 * `FormData` — see the note there.
 */
import type { ChildType } from '../model/types.js';
import type { RecordTarget } from '../model/recordTarget.js';

export type Payload = Record<string, unknown>;

/**
 * What {@see ProfileEndpointClient.send} accepts.
 *
 * A `FormData` is not a payload that happens to be encoded differently — it is
 * the one body the client must **not** set a content type for, because the
 * boundary is part of that header and only the browser knows it.
 */
export type RequestBody = Payload | FormData;

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
 * The Extbase plugin namespace of the endpoint plugin.
 *
 * Every parameter of a **mapped** request has to sit below it:
 * `RequestBuilder::build()` reads the parsed body as
 * `getParsedBody()[$pluginNamespace]` and the uploaded files as
 * `getUploadedFiles()[$pluginNamespace]`
 * (`cms-extbase/Classes/Mvc/Web/RequestBuilder.php:91-104`), and a part outside
 * it is not an argument as far as Extbase is concerned. The JSON endpoints do
 * not care — they decode the raw body themselves — but the upload is mapped by
 * Extbase, so this is the one request whose part names are dictated by the
 * framework.
 */
export const pluginNamespace = 'tx_modernextbasefrontendedit_ajax';

/**
 * The name of the file part: `<namespace>[<argument>][<property>]`.
 *
 * `profile` is the action argument the upload configuration is attached to and
 * `image` is the property `FileUploadConfiguration` names, so this string is the
 * client half of `FileUploadConfiguration('image')` on the `profile` argument.
 * `Argument::getUploadedFilesForProperty()` looks the file up by that property
 * name (`cms-extbase/Classes/Mvc/Controller/Argument.php:231`), and a part named
 * anything else is simply never found — with no error, because "no file
 * uploaded" is a valid request for a property that already holds one.
 */
export const imageUploadPart = `${pluginNamespace}[profile][image]`;

/**
 * The name of the profile uid part.
 *
 * Deliberately a sibling of the argument and not a member of it: mapping a uid
 * *into* the object would make the request say which record to load, and the
 * record is resolved from the session. Here it is what it is in every JSON
 * payload — a filter over the set the session owns.
 */
export const imageUidPart = `${pluginNamespace}[uid]`;

/**
 * The body of `uploadImage`: one file and one profile uid, as `multipart/form-data`.
 *
 * **Not JSON, and this is the only endpoint of which that is true.** A file in a
 * JSON body has to be base64, which costs a third more bytes on the wire and
 * holds the whole file in memory twice — once as the `File` and once as the
 * encoded string. A `FormData` streams the file as it is. The useful side effect
 * is that `$_POST` and `$_FILES` are populated for a multipart request, so
 * Extbase's property mapping and the modern upload API work normally instead of
 * having to be bypassed the way the JSON endpoints bypass them.
 *
 * The uid is stringified by `FormData` itself — every part of a multipart body
 * is text or a file, there is no number type — and the server reads it as it
 * reads every other request parameter.
 */
export function imageUploadBody(profileUid: number, file: File): FormData {
    const body = new FormData();
    body.append(imageUidPart, String(profileUid));
    // The third argument keeps the client filename, which the upload API uses as
    // the basename before it appends its random suffix. Without it a browser
    // sends "blob".
    body.append(imageUploadPart, file, file.name);

    return body;
}

/**
 * The payload of `removeImage`, which is an ordinary JSON call.
 *
 * It carries no file uid and no reference uid. Which image a profile has is a
 * property of the profile, the server knows it, and a client supplied uid would
 * be a second way to name a record — one the server would then have to prove is
 * the one it already has.
 */
export function removeImagePayload(profileUid: number): Payload {
    return { uid: profileUid };
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
