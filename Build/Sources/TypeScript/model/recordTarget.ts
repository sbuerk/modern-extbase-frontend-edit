/**
 * Which record an edit addresses.
 *
 * Every endpoint takes the same two optional keys to say what a payload is
 * about — `child` and `childUid` — and the client needs the same answer in
 * three places: to build a payload, to look a value up in the last server
 * known state, and to key the edit session of a record. A target is that
 * answer, as one value instead of two parameters threaded through everything.
 *
 * A target never carries the profile uid. That one is a property of the whole
 * document, not of the addressed record, and it comes from the state.
 */
import type { ChildType } from './types.js';

export interface ProfileTarget {
    readonly child: null;
    readonly childUid: null;
}

export interface ChildTarget {
    readonly child: ChildType;
    /**
     * `null` for a child that does not exist yet.
     *
     * The add form needs an identity before the record has one, so that its
     * drafts and its validation errors live in the same session machinery as
     * every other record. It is the one target that cannot appear in a payload,
     * which {@see isNewChildTarget} exists to make checkable.
     */
    readonly childUid: number | null;
}

export type RecordTarget = ProfileTarget | ChildTarget;

/**
 * The profile itself — a payload without `child`.
 */
export const profileTarget: ProfileTarget = Object.freeze({ child: null, childUid: null });

export function childTarget(child: ChildType, childUid: number): ChildTarget {
    return { child, childUid };
}

export function newChildTarget(child: ChildType): ChildTarget {
    return { child, childUid: null };
}

export function isChildTarget(target: RecordTarget): target is ChildTarget {
    return target.child !== null;
}

export function isNewChildTarget(target: RecordTarget): target is ChildTarget {
    return target.child !== null && target.childUid === null;
}

/**
 * A stable string identity of a target, for use as a map key.
 *
 * `profile`, `address:7`, `email:new`.
 */
export function targetKey(target: RecordTarget): string {
    if (target.child === null) {
        return 'profile';
    }

    return `${target.child}:${target.childUid ?? 'new'}`;
}

export function targetsEqual(one: RecordTarget, other: RecordTarget): boolean {
    return one.child === other.child && one.childUid === other.childUid;
}

/**
 * The label scope of a target: `profile`, `address` or `email`.
 *
 * Field and choice labels are keyed by it, because `type` exists on both child
 * collections and means a different set of values in each.
 */
export function targetScope(target: RecordTarget): string {
    return target.child ?? 'profile';
}
