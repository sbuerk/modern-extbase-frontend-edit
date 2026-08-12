/**
 * What a reader recognises a child record by.
 *
 * A profile's addresses and e-mail addresses are rendered as a list of records
 * that look alike, and until this existed nothing said which of them was which:
 * four addresses stacked under one another, distinguished only by a rule on
 * their leading edge, with a toolbar whose `Move up` had no visible referent.
 *
 * ## Why the identity is the record's own content, and never its position
 *
 * "Address 1", "Address 2" is the obvious labelling and it is wrong here. The
 * surface can **reorder** these records, so a number names where a record is
 * standing rather than what it is — press `Move up` and every label below it
 * changes, which is precisely the moment a reader most needs to keep track of
 * the thing they just moved. Content stays put when the order does not.
 *
 * ## Why this returns parts rather than a finished string
 *
 * Both halves need translating — the type through `choiceLabelKey`, and the
 * separator is a rendering decision — and a module that took a `LabelMap` to do
 * it would be a module that cannot be tested without one. What is genuinely
 * worth pinning is which field of which record type carries the identity, and
 * that is a pure function of the record.
 */
import type { ChildRecord, ChildType } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/types.js';

export interface ChildIdentity {
    /**
     * The stored `type` value — `home`, `work`, `business`, … — to be looked up
     * with {@see choiceLabelKey}. Empty when the record has none, which the
     * schema allows even though the control always offers one.
     */
    readonly type: string;
    /**
     * The one stored value that tells two records of the same type apart: the
     * first address line, or the e-mail address itself. Empty when unset.
     */
    readonly detail: string;
}

/**
 * The identity of one child record.
 *
 * `ChildRecord` is a union with no discriminating field of its own — an address
 * and an e-mail address both carry `uid`, `type` and `hidden` — so which member
 * it is has to be told rather than inferred. `child` is that, and it is the same
 * value the caller already used to fetch the record.
 *
 * A record that does not match the type it was announced as yields an empty
 * `detail` rather than throwing: this feeds a heading, and a heading is not
 * where a mismatch between two server responses should surface.
 */
export function childIdentity(child: ChildType, record: ChildRecord): ChildIdentity {
    const detail = child === 'email'
        ? ('email' in record ? record.email : '')
        : ('line1' in record ? record.line1 : '');

    return {
        type: record.type.trim(),
        detail: detail.trim(),
    };
}
