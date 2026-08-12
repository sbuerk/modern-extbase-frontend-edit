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
 * There is no primary/secondary distinction yet. The surface has one button per
 * intent and no competing calls to action, so a hierarchy would be decoration.
 * When the record actions grow it becomes worth revisiting.
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
     * Disabled means "not right now" — a save that is already in flight, a move
     * up on the first row. It has to read as unavailable rather than as missing,
     * because the button is a landmark the reader has already used once.
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
