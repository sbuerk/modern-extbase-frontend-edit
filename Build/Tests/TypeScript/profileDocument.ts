/**
 * The wire document the tests parse, and a helper to vary it.
 *
 * Shaped like `Http\ProfileDocumentFactory` builds it: the profile
 * scalars, then both collections. Three addresses rather than two, on purpose —
 * a two element collection hides reordering defects, because moving the first
 * record out of range and swapping it with the second produce the same list.
 */
export const profileDocument = {
    uid: 42,
    shortname: 'ada',
    firstname: 'Ada',
    lastname: 'Lovelace',
    birthday: '1815-12-10',
    bio: 'Mathematician.',
    hidden: false,
    addresses: [
        { uid: 7, type: 'home', line1: 'Ockham Park', line2: '', hidden: false },
        { uid: 8, type: 'work', line1: 'Analytical Engine', line2: '', hidden: true },
        { uid: 9, type: 'others', line1: 'Somewhere', line2: '', hidden: false },
    ],
    emails: [
        { uid: 21, type: 'private', email: 'ada@example.org', hidden: false },
    ],
};

/**
 * The same document with some of its values replaced, for "what the server
 * answered after a save" — the normalised value a client must not guess.
 */
export function profileDocumentWith(changes: Readonly<Record<string, unknown>>): Record<string, unknown> {
    return { ...profileDocument, ...changes };
}
