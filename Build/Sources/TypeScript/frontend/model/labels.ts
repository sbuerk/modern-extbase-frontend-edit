/**
 * The user facing strings of the editing surface.
 *
 * ## Why they arrive from the server
 *
 * The component renders its own controls, its own buttons and its own field
 * labels, so it needs text — and a literal in a TypeScript file is a string
 * that no XLIFF file knows about and no integrator can translate. Every one of
 * them is therefore looked up in a map the Fluid template renders into a
 * `data-labels` attribute, having produced it with `f:translate` from the same
 * XLIFF files the read templates use.
 *
 * ## A missing key shows the key
 *
 * {@see label} answers with the key itself rather than with an empty string, on
 * the same reasoning as the `default="{address.type}"` fallback in the
 * `Profile/AddressList` partial: a missing label has to be visible, because a
 * blank one looks like a rendering bug of the component and is found much
 * later. It is also what makes the label contract self-documenting in a
 * browser.
 */
export type LabelMap = Readonly<Record<string, string>>;

/**
 * Reads an unknown value as a label map, keeping the string entries.
 *
 * Never fails: a missing or malformed attribute yields an empty map and every
 * lookup then shows its key. The editing surface must not refuse to work
 * because a translation is missing.
 */
export function parseLabels(value: unknown): LabelMap {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return {};
    }
    const labels: Record<string, string> = {};
    for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
        if (typeof entry === 'string' && entry !== '') {
            labels[key] = entry;
        }
    }

    return labels;
}

export function label(labels: LabelMap, key: string): string {
    return labels[key] ?? key;
}

/**
 * The label of a field: `field.<scope>.<name>`.
 *
 * The scope is part of the key because `type` exists on both child collections
 * and means a different thing in each.
 */
export function fieldLabelKey(scope: string, field: string): string {
    return `field.${scope}.${field}`;
}

/**
 * The label of one accepted value of a select: `choice.<scope>.<name>.<value>`.
 */
export function choiceLabelKey(scope: string, field: string, value: string): string {
    return `choice.${scope}.${field}.${value}`;
}

/**
 * The label of a button or a state: `action.apply`, `state.hidden`, …
 */
export function actionLabelKey(action: string): string {
    return `action.${action}`;
}

export function sectionLabelKey(scope: string): string {
    return `section.${scope}`;
}

/**
 * The label of a record state the surface shows but cannot change:
 * `state.hidden`.
 */
export function stateLabelKey(state: string): string {
    return `state.${state}`;
}
