/**
 * Which fields a record has, and which control edits them.
 *
 * ## This list is a rendering hint and never a rule
 *
 * The authority for both "what may be written" and "what is a valid value" is
 * the PHP rule set — `Validation\ProfileRuleSet`, `AddressRuleSet`,
 * `EmailRuleSet` — and the endpoint refuses anything they do not declare, a
 * field name included. What is repeated here is only what a browser needs in
 * order to draw a control: the field order, the kind of input, the accepted
 * values of a select and the length a user is allowed to type before the server
 * says the same thing with a message.
 *
 * The duplication is therefore deliberate and bounded. It cannot make an
 * invalid value acceptable — it can only fail to prevent one, which the server
 * then rejects with a `422` that lands at the field. Keeping the two in sync is
 * a review item, not a runtime concern; the alternative is an endpoint that
 * publishes the rule set, which is a schema API this proof of concept does not
 * need.
 */
import type { ChildType } from './types.js';
import type { RecordTarget } from './recordTarget.js';

/**
 * How a field is edited.
 *
 * `line` is a single line text input, `text` a textarea, `date` a native date
 * input over the `Y-m-d` wire format, `choice` a select over a closed set.
 */
export type ControlKind = 'line' | 'text' | 'date' | 'choice';

export interface FieldDefinition {
    readonly name: string;
    readonly control: ControlKind;
    /**
     * The accepted values of a `choice` control, in the order they are offered.
     */
    readonly choices?: readonly string[];
    /**
     * The `maxlength` of the control, mirroring the rule set's
     * `StringLengthValidator` maximum.
     */
    readonly maxLength?: number;
    /**
     * The value a newly created record starts from.
     *
     * Mirrors the DTO's constructor default, which in turn mirrors the TCA
     * default of the column.
     */
    readonly initial?: string;
}

export const profileFields: readonly FieldDefinition[] = [
    { name: 'shortname', control: 'line', maxLength: 255 },
    { name: 'firstname', control: 'line', maxLength: 255 },
    { name: 'lastname', control: 'line', maxLength: 255 },
    { name: 'birthday', control: 'date' },
    { name: 'bio', control: 'text', maxLength: 5000 },
];

export const addressFields: readonly FieldDefinition[] = [
    { name: 'type', control: 'choice', choices: ['home', 'work', 'others'], initial: 'others' },
    { name: 'line1', control: 'line', maxLength: 255 },
    { name: 'line2', control: 'line', maxLength: 255 },
];

export const emailFields: readonly FieldDefinition[] = [
    { name: 'type', control: 'choice', choices: ['private', 'business', 'others'], initial: 'others' },
    { name: 'email', control: 'line', maxLength: 255 },
];

export function fieldsOfChild(child: ChildType | null): readonly FieldDefinition[] {
    switch (child) {
        case 'address':
            return addressFields;
        case 'email':
            return emailFields;
        default:
            return profileFields;
    }
}

export function fieldsOf(target: RecordTarget): readonly FieldDefinition[] {
    return fieldsOfChild(target.child);
}

export function fieldDefinition(target: RecordTarget, field: string): FieldDefinition | null {
    return fieldsOf(target).find((definition: FieldDefinition): boolean => definition.name === field) ?? null;
}

/**
 * The values a new record starts from, keyed by field name.
 */
export function initialValues(fields: readonly FieldDefinition[]): Record<string, string> {
    const values: Record<string, string> = {};
    for (const definition of fields) {
        values[definition.name] = definition.initial ?? '';
    }

    return values;
}
