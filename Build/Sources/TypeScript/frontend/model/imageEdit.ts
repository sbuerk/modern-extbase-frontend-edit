/**
 * What the surface needs in order to show and to replace the profile image.
 *
 * The image is not a field: it has no draft, no `Apply` and no `Cancel`. Picking
 * a file *is* the write, and there is nothing in between to keep — which is the
 * whole reason none of the {@see ../model/editState.js} machinery is reused for
 * it beyond the busy flag and the errors.
 *
 * What is left is four decisions, and all four are pure functions over the
 * document so that a test can make them without a browser.
 */
import type { ProfileImageRecord } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/types.js';

/**
 * The field name the endpoint reports an upload failure under.
 *
 * It has to be exactly the property name `FileUploadConfiguration` is
 * constructed with, because a `422` is keyed by field name and the surface shows
 * an entry *at the field it names* — an upload error named anything else lands
 * in the record's general errors, where nothing connects it to the file the user
 * just picked.
 */
export const imageField = 'image';

/**
 * The `accept` attribute of the file input.
 *
 * A rendering hint and never a rule, on the same reasoning as
 * {@see ./fieldDefinitions.js}: `accept` filters the file picker and prevents
 * nothing at all — a user can pick "all files" in every browser dialog, and a
 * request can be made without one. What decides is the server's `MimeTypeValidator`,
 * plus the `FileNameValidator` and `FileExtensionMimeTypeConsistencyValidator`
 * that are enforced whether they are configured or not. This list therefore has
 * to *mirror* the configured MIME types and cannot be authoritative for them;
 * one that drifted apart costs a round trip and a message at the field, not a
 * hole.
 */
export const imageAccept = 'image/jpeg,image/png,image/gif,image/webp';

/**
 * Whether there is something to draw an `<img>` from.
 *
 * Distinct from "the profile has an image": a reference whose file is gone has
 * no public URL and is still a reference, so it is still removable. Conflating
 * the two would leave the owner with a record they cannot repair from the
 * frontend.
 */
export function isDisplayable(image: ProfileImageRecord | null): image is ProfileImageRecord {
    return image !== null && image.publicUrl !== '';
}

/**
 * The alternative text of the image, by the rule `Profile/Image.html` applies.
 *
 * The alternative text stored on the file reference wins, and the translated
 * sentence is the fallback — the same order as the partial's
 * `f:if(condition: image.alternative, …)`, so the served page and the enhanced
 * surface describe the same image the same way.
 *
 * The substitution is done here rather than by `f:translate` because the name is
 * part of the sentence and the name changes while the surface is open: a text
 * rendered once by the server would keep describing the profile as it was
 * loaded, and the alternative text would be the last thing anybody noticed had
 * gone stale.
 *
 * @param template the `profile.image.alt` label, carrying one `%s`
 * @param name the profile name, as {@see ../model/profileRecord.js} computes it
 */
export function imageAlternative(image: ProfileImageRecord, template: string, name: string): string {
    if (image.alternative !== '') {
        return image.alternative;
    }

    return template.replace('%s', name);
}

/**
 * The messages shown at the image field, with the "pick it again" notice last.
 *
 * **Nothing is moved into storage when validation fails.** Extbase validates
 * before it maps, and on an error the mapping never runs, so a rejected upload
 * leaves nothing behind anywhere — not in the storage, not in a temporary
 * folder, and not in this component, which drops its reference to the `File` as
 * soon as the request is out. The user therefore has to pick the file again, and
 * the surface has to *say* so: an error message alone reads as "that file was no
 * good", which is true, but it leaves the impression that the file is still
 * selected and one correction away from being saved.
 *
 * The notice is appended rather than substituted, because the server's messages
 * are the ones that say *why* it was rejected. It is added once and only when
 * there is a failure to explain; an empty notice — a site that did not translate
 * the key — adds nothing rather than an empty bullet.
 */
export function uploadFailureMessages(messages: readonly string[], notice: string): string[] {
    if (notice === '' || messages.includes(notice)) {
        return [...messages];
    }

    return [...messages, notice];
}
