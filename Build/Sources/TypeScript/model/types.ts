/**
 * The wire types of the editing endpoints.
 *
 * They describe the `data` object every one of the seven endpoints answers with
 * — `Http\ProfileDocumentFactory` builds it, and it is
 * the same document for a read, a full save and a single field save. The edit
 * plugin embeds the very same document in the page, from the very same factory,
 * so the record the component starts from and the record a save answers with
 * cannot describe the same profile differently. Nothing
 * here is a view model: what the server returns is what the component renders,
 * so a second shape between the two would only be a place for the client's idea
 * of the record to drift away from the server's.
 */

/**
 * The `child` discriminator of a payload.
 *
 * A closed set on the server as well, checked in `ProfileAjaxController::childType()`.
 */
export type ChildType = 'address' | 'email';

/**
 * The child collections, in the order they are rendered.
 */
export const childTypes: readonly ChildType[] = ['address', 'email'];

export interface AddressRecord {
    readonly uid: number;
    readonly type: string;
    readonly line1: string;
    readonly line2: string;
    readonly hidden: boolean;
}

export interface EmailRecord {
    readonly uid: number;
    readonly type: string;
    readonly email: string;
    readonly hidden: boolean;
}

export type ChildRecord = AddressRecord | EmailRecord;

/**
 * The whole aggregate: the profile and both of its collections.
 *
 * `hidden` is readable and not writable — no endpoint changes it, and the
 * component therefore only displays it. `birthday` is a `Y-m-d` string or `''`
 * for "no birthday", pinned by `Dto\ProfileData::BIRTHDAY_FORMAT`, so what is
 * read back is spelled exactly like what may be written.
 */
export interface ProfileRecord {
    readonly uid: number;
    readonly shortname: string;
    readonly firstname: string;
    readonly lastname: string;
    readonly birthday: string;
    readonly bio: string;
    readonly hidden: boolean;
    readonly addresses: readonly AddressRecord[];
    readonly emails: readonly EmailRecord[];
}
