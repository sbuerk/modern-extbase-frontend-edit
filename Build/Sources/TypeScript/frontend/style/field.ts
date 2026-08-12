/**
 * The chrome shared by the two field shaped elements: a label, a body holding
 * either the stored value or a control, the actions belonging to it, and the
 * errors about it.
 *
 * `editField` and `editImage` render the same skeleton with the same class names
 * and used to carry two near identical copies of these rules, which had already
 * drifted — the image element styled its `button` and the field element did not,
 * and both spelled the error colour as a literal `#a4141a`. One copy, consumed
 * by both, is why a change to the field rhythm now lands on the image too.
 *
 * The class names are load bearing beyond CSS: the acceptance specs address the
 * surface through `.field-value`, `.field-control` and `.field-errors` because
 * those are structural rather than presentational — see
 * `Tests/Acceptance/Support/profileEditPage.ts`. Renaming one is a test change,
 * not a styling change.
 */
import { css } from 'lit';
import type { CSSResult } from 'lit';

export const field: CSSResult = css`
    /*
     * A field is a row: label, value, and the action belonging to it. It used to
     * be a stack, and that was the single largest thing wrong with the surface —
     * measured, not guessed. Twenty-six fields at 76 pixels each accounted for
     * 64% of a 3087 pixel page, and each of those 76 pixels showed about twenty
     * pixels of text.
     *
     * A row cannot be made shorter than its tallest control, and the control is
     * a 36 pixel touch target that is not negotiable. Putting the label beside
     * it rather than above it is therefore the only way to recover the height,
     * and it puts the action next to the value it acts on as a side effect.
     *
     * "flex-wrap" rather than a query: when the container is too narrow for the
     * label column and the body side by side, the body drops to its own line and
     * the old stack comes back. That responds to the width of the *container*,
     * which is what a plugin dropped into an unknown column actually needs — a
     * viewport media query would be answering a different question.
     */
    .field {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        gap: var(--frontend-edit-gap-within) var(--frontend-edit-space-md);
    }

    /*
     * The label is quieter than the value it names. A field the visitor is
     * reading should show them their own data first; the label is the thing they
     * consult only when the value is ambiguous.
     *
     * Its top padding is the control's, so the first line of a label sits level
     * with the first line of the value beside it whatever either contains. That
     * is deliberate rather than "align-items: baseline": the image field renders
     * its actions before its value, and a flex container takes its baseline from
     * its first item, so baseline alignment would line the label up with a
     * button in one field and with text in the next.
     */
    .field-label {
        flex: 0 0 var(--frontend-edit-label-width);
        padding-block: var(--frontend-edit-control-padding-block);
        font-size: var(--frontend-edit-font-size-sm);
        font-weight: var(--frontend-edit-label-weight);
        color: var(--frontend-edit-color-muted);
    }

    .field-body {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        gap: var(--frontend-edit-space-sm);
        flex: 1 1 18rem;
        min-width: 0;
    }

    /*
     * The value and the control that replaces it share their sizing, so
     * switching a field into edit mode does not reflow the row around it.
     */
    .field-value,
    .field-control {
        flex: 1 1 12rem;
        min-width: 0;
    }

    /*
     * "box-sizing" is the whole point of this rule, and it was a defect before.
     * The minimum height is meant to be the control's, so that a value and the
     * control that replaces it occupy the same box — but "min-height" applies to
     * the content box, and the padding was adding to it. The value measured 48
     * pixels against a 36 pixel button, the rows did not line up, and switching
     * into edit mode reflowed the row that a comment here claimed it would not.
     */
    .field-value {
        box-sizing: border-box;
        padding-block: var(--frontend-edit-control-padding-block);
        min-height: var(--frontend-edit-control-min-height);
        white-space: pre-wrap;
    }

    /*
     * An empty field still occupies its row and says so with an em dash. Leaving
     * it blank would read as a rendering fault rather than as an absent value,
     * and would give the "Edit" button nothing to sit beside.
     */
    .field-value.is-empty::after {
        content: '—';
        color: var(--frontend-edit-color-muted);
    }

    .field-actions {
        display: flex;
        flex-wrap: wrap;
        gap: var(--frontend-edit-space-xs);
        align-items: center;
    }

    .field-errors {
        margin: 0;
        padding: 0;
        list-style: none;
        color: var(--frontend-edit-color-danger);
        font-size: var(--frontend-edit-font-size-sm);
    }

    /*
     * Busy is a request in flight. The surface stays readable and stays in
     * place — it is dimmed, not replaced by a spinner — because the value shown
     * is still the last one the server confirmed and remains true until the
     * answer arrives.
     */
    :host([busy]) {
        opacity: var(--frontend-edit-busy-opacity);
    }
`;
