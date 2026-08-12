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
    .field {
        display: grid;
        gap: var(--frontend-edit-space-xs);
        padding: var(--frontend-edit-space-xs) 0;
    }

    /*
     * The label is quieter than the value it names. A field the visitor is
     * reading should show them their own data first; the label is the thing they
     * consult only when the value is ambiguous.
     */
    .field-label {
        font-size: var(--frontend-edit-font-size-sm);
        font-weight: var(--frontend-edit-label-weight);
        color: var(--frontend-edit-color-muted);
    }

    .field-body {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        gap: var(--frontend-edit-space-sm);
    }

    /*
     * The value and the control that replaces it share their sizing, so
     * switching a field into edit mode does not reflow the row around it. The
     * padding matches the control's so the text does not shift either.
     */
    .field-value,
    .field-control {
        flex: 1 1 12rem;
        min-width: 0;
    }

    .field-value {
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
