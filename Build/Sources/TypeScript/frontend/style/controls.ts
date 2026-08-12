/**
 * Buttons, form controls and their states, shared by every element that draws
 * one.
 *
 * All three components draw buttons, and before this module each of them said
 * `button { font: inherit; }` and stopped there — which left a user agent button
 * in the middle of an editing surface, at a different height and with a
 * different corner radius than the input beside it. The rules here are the
 * smallest set that makes a control look deliberate: one box, one border, one
 * focus ring, and states that are visible without being loud.
 *
 * Everything is an element selector. That is deliberate — the components render
 * plain `<button>`, `<input>`, `<select>` and `<textarea>` with no presentational
 * class, so styling them by element keeps the markup about meaning and keeps
 * these rules from having to be applied by hand at every call site.
 *
 * ## The hierarchy, and why it is an attribute rather than a class
 *
 * Three levels, and the default is the unmarked one:
 *
 * | Variant     | Meaning                              | Buttons                        |
 * |-------------|--------------------------------------|--------------------------------|
 * | `primary`   | commits a pending change             | Apply, Save all fields, Add    |
 * | *(default)* | changes what the surface is doing    | Edit, Cancel, Move, Hide       |
 * | `danger`    | destroys a record or a file          | Remove                         |
 *
 * The variant travels in `data-variant`, not in `class`, and the reason is a
 * convention this repository already relies on: class names here are
 * **structural** — `.field-value`, `.field-control`, `.field-errors` are how the
 * acceptance suite addresses the surface, precisely because they describe what a
 * thing is and not how it looks. A presentational token in the same attribute
 * would put a rename of an appearance concern in the same place as a selector a
 * test depends on. Two attributes, two concerns, and `data-variant` can be
 * renamed freely.
 *
 * Only two levels are marked. Boldness is spent in one place: `primary` is the
 * single filled thing in a row, `danger` states itself in colour and does not
 * fill until the pointer is on it, and everything else — `Cancel` included — is
 * the plain button. A third, quieter level for `Cancel` was considered and left
 * out; it would make the pair `Apply` / `Cancel` read as one real button beside
 * one hint, and cancelling is an ordinary thing to want.
 */
import { css } from 'lit';
import type { CSSResult } from 'lit';

export const controls: CSSResult = css`
    button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: var(--frontend-edit-space-xs);
        min-height: var(--frontend-edit-control-min-height);
        padding: var(--frontend-edit-control-padding-block) var(--frontend-edit-control-padding-inline);
        border: var(--frontend-edit-border-width) solid var(--frontend-edit-color-border);
        border-radius: var(--frontend-edit-radius);
        background-color: var(--frontend-edit-color-surface);
        color: inherit;
        font: inherit;
        font-family: var(--frontend-edit-font-family);
        line-height: 1.2;
        cursor: pointer;
        transition:
            background-color var(--frontend-edit-transition-duration) var(--frontend-edit-transition-easing),
            border-color var(--frontend-edit-transition-duration) var(--frontend-edit-transition-easing);
    }

    button:hover:not(:disabled) {
        border-color: var(--frontend-edit-color-border-strong);
        background-color: var(--frontend-edit-color-surface-sunken);
    }

    button:active:not(:disabled) {
        border-color: var(--frontend-edit-color-accent);
    }

    /*
     * Sized in "em" so a glyph tracks the type around it rather than being
     * pinned to a pixel size the site cannot influence, and "block" because an
     * inline SVG otherwise sits on the text baseline and drags the button's line
     * box down with its descender space.
     */
    .icon {
        display: block;
        width: 1.15em;
        height: 1.15em;
        flex: none;
    }

    /*
     * A record toolbar - move, hide, remove, repeated once per child - is the
     * one place the labels are dropped. Four wide text buttons per child were
     * the heaviest thing on the surface, and row level actions are the case
     * where an icon alone is understood; it is the same treatment the TYPO3
     * backend gives the equivalent controls in a record list.
     *
     * The text is hidden, never removed. It stays in the accessibility tree, in
     * "textContent" and in the accessible name, so every spec that addresses a
     * button by its label keeps working and a screen reader still hears "Move
     * up" rather than "button".
     */
    button[data-icon-only] {
        padding-inline: var(--frontend-edit-control-padding-block);
    }

    button[data-icon-only] .button-label {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        overflow: hidden;
        white-space: nowrap;
        clip-path: inset(50%);
    }

    /*
     * The one filled button in a row: it commits the change the reader came to
     * make. Filled rather than merely tinted, because in a row of four or five
     * bordered buttons a tint is not a hierarchy, it is a shade.
     */
    button[data-variant='primary'] {
        border-color: var(--frontend-edit-color-accent);
        background-color: var(--frontend-edit-color-accent);
        color: var(--frontend-edit-color-accent-contrast);
    }

    button[data-variant='primary']:hover:not(:disabled),
    button[data-variant='primary']:active:not(:disabled) {
        border-color: var(--frontend-edit-color-accent-hover);
        background-color: var(--frontend-edit-color-accent-hover);
    }

    /*
     * Destructive, and quiet until it is about to be pressed. A permanently red
     * button in a row of neutral ones shouts at a reader who is not going to
     * press it, and the row it sits in — move, hide, remove — is one a reader
     * uses for the other three far more often. Colour identifies it; the fill
     * arrives on hover, when the intent is already there.
     */
    button[data-variant='danger'] {
        color: var(--frontend-edit-color-danger);
    }

    button[data-variant='danger']:hover:not(:disabled),
    button[data-variant='danger']:active:not(:disabled) {
        border-color: var(--frontend-edit-color-danger);
        background-color: var(--frontend-edit-color-danger-surface);
    }

    button[data-variant='danger']:focus-visible {
        outline-color: var(--frontend-edit-color-danger);
    }

    /*
     * Disabled means "not right now" — a save that is already in flight, a move
     * up on the first row. It has to read as unavailable rather than as missing,
     * because the button is a landmark the reader has already used once.
     *
     * Listed after the variants so a disabled primary is dimmed rather than
     * drawn at full strength; the variants set colour, this sets opacity, and
     * the two compose.
     */
    button:disabled {
        opacity: var(--frontend-edit-busy-opacity);
        cursor: default;
    }

    input,
    select,
    textarea {
        min-height: var(--frontend-edit-control-min-height);
        padding: var(--frontend-edit-control-padding-block) var(--frontend-edit-control-padding-inline);
        border: var(--frontend-edit-border-width) solid var(--frontend-edit-color-border);
        border-radius: var(--frontend-edit-radius);
        background-color: var(--frontend-edit-color-surface);
        color: inherit;
        font: inherit;
        font-family: var(--frontend-edit-font-family);
    }

    textarea {
        min-height: 6rem;
        resize: vertical;
    }

    /*
     * The file input is the one control whose box the browser owns: the button
     * inside it cannot be reached from here, and padding it like a text field
     * only pushes that button around. It keeps the shared type and nothing else.
     */
    input[type='file'] {
        min-height: auto;
        padding: 0;
        border: none;
        background-color: transparent;
    }

    /*
     * One focus ring for every control, and "focus-visible" rather than "focus"
     * so a mouse click on a button does not leave a ring behind it. The ring is
     * an outline on purpose — it is drawn outside the border box and therefore
     * never moves the layout, which a border swap would.
     */
    button:focus-visible,
    input:focus-visible,
    select:focus-visible,
    textarea:focus-visible {
        outline: var(--frontend-edit-focus-width) solid var(--frontend-edit-focus-color);
        outline-offset: var(--frontend-edit-focus-offset);
    }

    /*
     * Invalid is stated twice, in colour and in weight, because colour alone is
     * not a signal every reader receives. The message itself is in the error
     * list; this only says which control it is about.
     */
    [aria-invalid='true'] {
        border-color: var(--frontend-edit-color-danger);
        box-shadow: 0 0 0 var(--frontend-edit-border-width) var(--frontend-edit-color-danger);
    }

    /*
     * A rejected field is focused almost by definition — the surface puts the
     * cursor back into it — so these two rings are drawn together far more often
     * than either is drawn alone. Left in their own colours they read as two
     * separate pieces of news, and the blue one is the louder of them, which
     * makes the field look focused rather than wrong. One colour, one message.
     */
    [aria-invalid='true']:focus-visible {
        outline-color: var(--frontend-edit-color-danger);
    }
`;
