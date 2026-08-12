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
 * The profile image, as `Domain\Model\ProfileImage` exposes it.
 *
 * Scalars only, and every one of them the server's: the filename carries a
 * random suffix the upload API generated, so **the URL cannot be predicted by a
 * client**. A component that assembled one from the name it uploaded would show
 * a broken image after every upload. It is read from the document of the
 * response and from nowhere else.
 *
 * `publicUrl` is `''` when the file behind the reference is gone — the read side
 * value object types it `?string`, and {@see parseProfileImage} normalises the
 * `null` to the empty string like every other missing scalar. The reference then
 * still exists and is still removable, which is why "no image" is
 * `image === null` and not an empty URL.
 *
 * `width` and `height` stay `null` for a file that carries no image dimensions,
 * because an `<img>` with an empty `width` attribute is invalid markup — the
 * same reason `Profile/Image.html` writes both attributes conditionally.
 */
export interface ProfileImageRecord {
    readonly uid: number;
    readonly fileUid: number;
    readonly publicUrl: string;
    readonly name: string;
    readonly extension: string;
    readonly mimeType: string;
    readonly size: number;
    readonly title: string;
    readonly alternative: string;
    readonly width: number | null;
    readonly height: number | null;
}

/**
 * The whole aggregate: the profile, its image and both of its collections.
 *
 * `hidden` is readable and not writable — no endpoint changes it, and the
 * component therefore only displays it. `birthday` is a `Y-m-d` string or `''`
 * for "no birthday", pinned by `Dto\ProfileData::BIRTHDAY_FORMAT`, so what is
 * read back is spelled exactly like what may be written.
 *
 * `image` is `null` for a profile that has none, which is a valid state and not
 * an error.
 */
export interface ProfileRecord {
    readonly uid: number;
    readonly shortname: string;
    readonly firstname: string;
    readonly lastname: string;
    readonly birthday: string;
    readonly bio: string;
    readonly hidden: boolean;
    readonly image: ProfileImageRecord | null;
    readonly addresses: readonly AddressRecord[];
    readonly emails: readonly EmailRecord[];
}
