/**
 * The last server known state, and every question the UI asks of it.
 *
 * There is no client side model that is updated as the user types. The state is
 * exactly one thing — the `data` document of the most recent successful
 * response, or the one the server rendered into the markup — and it is replaced
 * wholesale, never patched. Two rules of the design fall out of that and are
 * the reason this module exists at all:
 *
 * - **The server is the source of truth.** A value the server normalised
 *   becomes visible because the next render reads it from here, not because
 *   anything compared it with what was sent.
 * - **Cancel restores the last server known value**, which is what
 *   {@see fieldValue} returns — the value as of the most recent successful
 *   save, not the value the page was loaded with. Those differ as soon as one
 *   save has succeeded.
 *
 * Everything is a function over an immutable record rather than a class holding
 * one. Applying a response is then an assignment in the component, which is
 * also what lit needs in order to notice it.
 */
import type { AddressRecord, ChildRecord, ChildType, EmailRecord, ProfileRecord } from './types.js';
import type { RecordTarget } from './recordTarget.js';
import { fieldsOf } from './fieldDefinitions.js';

/**
 * Reads an unknown value as a profile document, or answers `null`.
 *
 * Defensive rather than trusting, because the input is a `data-` attribute or a
 * decoded response body: both are strings until proven otherwise. A missing or
 * mistyped scalar is normalised to the empty value of its type, while a
 * structurally impossible document — not an object, no positive `uid` — is
 * rejected outright. The component treats `null` as "do not enhance" and leaves
 * the server rendered markup in place, which is the only failure mode that
 * keeps the page readable.
 */
export function parseProfileRecord(value: unknown): ProfileRecord | null {
    if (!isObject(value)) {
        return null;
    }
    const uid = readUid(value.uid);
    if (uid === null) {
        return null;
    }

    return {
        uid,
        shortname: readString(value.shortname),
        firstname: readString(value.firstname),
        lastname: readString(value.lastname),
        birthday: readString(value.birthday),
        bio: readString(value.bio),
        hidden: value.hidden === true,
        addresses: readChildren(value.addresses, parseAddressRecord),
        emails: readChildren(value.emails, parseEmailRecord),
    };
}

export function parseAddressRecord(value: unknown): AddressRecord | null {
    if (!isObject(value)) {
        return null;
    }
    const uid = readUid(value.uid);
    if (uid === null) {
        return null;
    }

    return {
        uid,
        type: readString(value.type),
        line1: readString(value.line1),
        line2: readString(value.line2),
        hidden: value.hidden === true,
    };
}

export function parseEmailRecord(value: unknown): EmailRecord | null {
    if (!isObject(value)) {
        return null;
    }
    const uid = readUid(value.uid);
    if (uid === null) {
        return null;
    }

    return {
        uid,
        type: readString(value.type),
        email: readString(value.email),
        hidden: value.hidden === true,
    };
}

/**
 * The addressed record, or `null` when the target names one that is gone.
 *
 * A child that another session deleted is exactly that case, and it is not
 * exceptional: the component renders from the state, so a target that no longer
 * resolves simply stops being rendered.
 */
export function recordOf(profile: ProfileRecord, target: RecordTarget): ProfileRecord | ChildRecord | null {
    if (target.child === null) {
        return profile;
    }
    if (target.childUid === null) {
        return null;
    }

    return childRecord(profile, target.child, target.childUid);
}

export function childRecord(profile: ProfileRecord, child: ChildType, childUid: number): ChildRecord | null {
    return childrenOf(profile, child).find((record: ChildRecord): boolean => record.uid === childUid) ?? null;
}

export function childrenOf(profile: ProfileRecord, child: ChildType): readonly ChildRecord[] {
    return child === 'address' ? profile.addresses : profile.emails;
}

export function childUids(profile: ProfileRecord, child: ChildType): number[] {
    return childrenOf(profile, child).map((record: ChildRecord): number => record.uid);
}

/**
 * The last server known value of one field, as a string.
 *
 * `''` for an unknown field or a record that is gone — the same value an empty
 * field has, because a control cannot show anything else and because the server
 * is the one that decides whether an empty value is acceptable.
 */
export function fieldValue(profile: ProfileRecord, target: RecordTarget, field: string): string {
    const record = recordOf(profile, target);
    if (record === null) {
        return '';
    }
    const value: unknown = (record as unknown as Record<string, unknown>)[field];

    return typeof value === 'string' ? value : '';
}

/**
 * Every writable value of one record, keyed by field name.
 *
 * This is what a whole record submit sends, and what a whole record edit starts
 * its drafts from.
 */
export function recordValues(profile: ProfileRecord, target: RecordTarget): Record<string, string> {
    const values: Record<string, string> = {};
    for (const definition of fieldsOf(target)) {
        values[definition.name] = fieldValue(profile, target, definition.name);
    }

    return values;
}

export function isChildHidden(profile: ProfileRecord, child: ChildType, childUid: number): boolean {
    return childRecord(profile, child, childUid)?.hidden ?? false;
}

/**
 * The full intended order of a collection after moving one record.
 *
 * The reorder endpoint takes a **permutation of the whole collection** and
 * refuses anything else, because the submitted list replaces the collection
 * wholesale and every record it omits is then deleted as an orphan
 * (`ProfileAjaxController::ordered()`). So a move is expressed as the complete
 * resulting order rather than as an index, and this is the one place that
 * computes it.
 *
 * A move that would leave the collection — the first record upwards, the last
 * downwards — and a uid that is not a member both answer with the unchanged
 * order, so the caller can compare and skip a request that would change
 * nothing.
 *
 * @param offset positions to move by, negative towards the front
 */
export function movedChildOrder(
    profile: ProfileRecord,
    child: ChildType,
    childUid: number,
    offset: number,
): number[] {
    const order = childUids(profile, child);
    const from = order.indexOf(childUid);
    if (from === -1) {
        return order;
    }
    const to = from + offset;
    if (to < 0 || to >= order.length) {
        return order;
    }
    const moved = [...order];
    moved.splice(from, 1);
    moved.splice(to, 0, childUid);

    return moved;
}

function readChildren<T>(value: unknown, parse: (entry: unknown) => T | null): T[] {
    if (!Array.isArray(value)) {
        return [];
    }
    const records: T[] = [];
    for (const entry of value) {
        const record = parse(entry);
        if (record !== null) {
            records.push(record);
        }
    }

    return records;
}

function readUid(value: unknown): number | null {
    return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null;
}

function readString(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
