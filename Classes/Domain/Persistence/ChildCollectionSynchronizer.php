<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Brings a parent's live child collection into the intended shape and order.
 *
 * This is the mechanism half of the write path. It touches the object graph
 * only — it calls no repository, no `PersistenceManager` and no
 * `persistAll()` — which is what makes it testable without a database and what
 * keeps the persistence decisions in one place, {@see ProfilePersistenceService}.
 *
 * It does the three things the Extbase persistence layer does not do, and every
 * one of them fails silently rather than loudly when it is left out.
 *
 * ## 1. Reordering: `attach()` on a contained object reorders nothing
 *
 * `ObjectStorage` is a plain PHP array keyed by `spl_object_hash()`, and
 * `offsetSet()` — which `attach()` is an alias of — writes that key:
 *
 * ```php
 * // Classes/Persistence/ObjectStorage.php:148-155, v13 and v14 identical
 * public function offsetSet(mixed $object, mixed $information): void
 * {
 *     $this->isModified = true;
 *     $this->storage[spl_object_hash($object)] = ['obj' => $object, 'inf' => $information];
 *     $this->positionCounter++;
 *     $this->addedObjectsPositions[spl_object_hash($object)] = $this->positionCounter;
 * }
 * ```
 *
 * Writing an **existing** key updates the element in place and PHP keeps it at
 * its current array position. Attaching a contained object therefore bumps
 * `positionCounter` and marks the storage dirty, while iteration order — and
 * with it the order Extbase writes — is unchanged. The operation looks like it
 * worked and produces the old order in the database. `ObjectStorage` has no
 * `sort()`, `move()` or `setOrder()`; emptying and refilling it is the only
 * reorder there is.
 *
 * Detaching **all** members is what makes the refill start from `1` again:
 *
 * ```php
 * // Classes/Persistence/ObjectStorage.php:183-187, v13 and v14 identical
 * unset($this->storage[spl_object_hash($object)]);
 * if (empty($this->storage)) {
 *     $this->positionCounter = 0;
 * }
 * ```
 *
 * That reset is why {@see synchronize()} detaches everything before it attaches
 * anything, rather than detaching only what moved. `toArray()` is iterated
 * rather than the storage itself, because detaching while iterating the live
 * storage mutates what is being iterated.
 *
 * ## 2. `pid` for new children, from the parent record
 *
 * `Backend::determineStoragePageIdForNewRecord()` (v14 `Backend.php:855`)
 * prefers the object's own pid over `newRecordStoragePid` and over
 * `persistence.storagePid`, and returns it as soon as it is not `null`. A new
 * child that does not carry a pid therefore lands wherever the configuration
 * happens to point, and a child that carries a pid a request was allowed to
 * choose is a write-anywhere primitive.
 *
 * The pid is assigned here from `$parent->getPid()`, on new children only, and
 * from no other source. The mapper does the same when it constructs a child;
 * repeating it is deliberate rather than redundant — this is the last layer
 * before the write, it is idempotent, and it also covers a child that reached
 * the collection without going through the mapper. Assigning it to an
 * **existing** child would move the row, so `_isNew()` gates it.
 *
 * ## 3. Orphans, diffed by uid
 *
 * Removing a child from the collection does not delete it. Extbase writes `0`
 * into the parent pointer, `''` into `foreign_table_field` and `0` into the
 * sorting column, and leaves the row otherwise untouched
 * (`Backend::detachObjectFromParentObject()`, v14 `Backend.php:500-513`):
 * `deleted = 0`, `hidden` unchanged, `pid` unchanged. The result is invisible in
 * the backend because it has no parent, it still occupies the reference index,
 * it sorts *first* in any query ordering by the sorting column without a parent
 * filter, and nothing will ever clean it up.
 *
 * {@see synchronize()} therefore returns the children that dropped out, and the
 * caller deletes them. `#[Cascade('remove')]` — which would make
 * `persistObjectStorage()` do it — is rejected for three independent reasons,
 * see `docs/frontend-edit/persistence-and-sorting.md`.
 *
 * The diff is by **uid**, not by object identity. Extbase's identity map makes
 * the two equivalent in practice — `Session::buildIdentifier()` keys on uid and
 * the language content identifier, and not on query settings, so the instance
 * the edit repository returns for a uid is the instance the parent's relation
 * holds. Not depending on that is free here and removes a whole class of
 * failure: a diff by identity would delete a row and immediately re-attach it
 * if the two sets ever came from different sessions.
 *
 * ## What this class does not do
 *
 * It does not check ownership. `$owned` is the already owner constrained set,
 * resolved by the caller through the edit repositories and
 * `Security\ProfileOwnershipResolverInterface`; see {@see synchronize()}.
 *
 * The service is stateless and holds no dependencies at all.
 */
final readonly class ChildCollectionSynchronizer
{
    /**
     * Replaces the contents of `$collection` with `$intended`, in that order,
     * and reports which of the `$owned` children are no longer part of it.
     *
     * **The returned children are not deleted here. The caller must delete
     * them**, through the repository that manages their type and before
     * `persistAll()`. That split is deliberate: this class stays free of
     * persistence so it can be tested without a database, and the deletion has
     * to happen in the same unit of work as the write, which only the caller
     * knows about.
     *
     * ## Ownership is the caller's boundary
     *
     * `$owned` **is** the authorization: it is the set of persisted children the
     * caller has already established belong to this parent, and a child that is
     * not in it can only enter `$intended` as a new record. Ownership is
     * deliberately **not** re-checked here. Two copies of an authorization rule
     * drift, and the copy in the layer nobody looks at is the one that drifts
     * first — the rule lives in the controller and in the owner constrained
     * repository finders, and nowhere else.
     *
     * ## What ends up in the database
     *
     * Extbase walks the storage in iteration order with a counter starting at
     * `1` and writes it into the column named by the parent column's
     * `foreign_sortby` (v14 `Backend.php:481-484`):
     *
     * ```php
     * $childSortByFieldName = $parentColumnMap->childSortByFieldName;
     * if (!empty($childSortByFieldName)) {
     *     $row[$childSortByFieldName] = $sortingPosition;
     * }
     * ```
     *
     * The values are dense `1..n`, which is what `RelationHandler::writeForeignField()`
     * writes for inline children in the backend as well
     * (`$updateValues[$sortby] = ++$c;`, `cms-core/Classes/Database/RelationHandler.php:1060`).
     * A record reordered here can be reordered in the backend afterwards without
     * a renumbering step, and the other way round.
     *
     * Four conditions have to hold for that write to happen at all, and each one
     * loses the sorting silently rather than loudly:
     *
     * 1. The **parent** column's TCA carries `foreign_sortby`. It feeds
     *    `ColumnMap::$childSortByFieldName` and nothing else does
     *    (`Mapper/ColumnMapFactory.php:130`, v14). `ctrl.sortby` on the child
     *    table is invisible to the write path — both are needed, and neither
     *    substitutes for the other.
     * 2. The relation is `HAS_MANY`. `Backend::attachObjectToParentObject()`
     *    dispatches to `attachObjectToParentObjectRelationHasMany()` only for
     *    `HAS_MANY`, and that method re-asserts it with an
     *    `IllegalRelationTypeException` (1345368105).
     * 3. The storage reports itself dirty, or the parent is new, or the storage
     *    was emptied — otherwise `persistObjectStorage()` is never called
     *    (`Backend.php:281`). `_isDirty()` is a plain flag set by `offsetSet()`
     *    and `offsetUnset()` only, so changing a property *on* a contained child
     *    does not set it. The detach/attach cycle below is what sets it.
     * 4. The computed position differs from the clean one. The loop at
     *    `Backend.php:360-389` writes only for a new child, a child the clean
     *    storage does not know — every hidden child, which never was in the
     *    parent's collection — or a child whose position moved.
     *
     * The fourth condition also means a collection whose order did not change is
     * not renumbered. Pre-existing gaps in the sorting column are preserved
     * rather than repaired; they are harmless, because every read orders by the
     * column rather than trusting its values.
     *
     * @template T of AbstractEntity
     * @param AbstractEntity $parent the persisted parent record, the only source of the child pid
     * @param ObjectStorage<T> $collection the parent's **live** collection, not a copy
     * @param ObjectStorage<T> $intended the intended set, in the intended order
     * @param list<T> $owned the owner constrained set of persisted children, resolved by the caller
     * @return list<T> the children that dropped out and must be deleted by the caller
     */
    public function synchronize(
        AbstractEntity $parent,
        ObjectStorage $collection,
        ObjectStorage $intended,
        array $owned,
    ): array {
        if ($intended === $collection) {
            // Passing the live collection as the intended set would empty it in
            // the detach loop below and leave nothing to re-attach — a silently
            // cleared relation. The intended set is built by the mapper and is
            // always a separate storage.
            throw new \InvalidArgumentException(
                'The intended set must not be the live collection of the parent record.',
                1786493011
            );
        }

        $parentPid = $parent->getPid();
        if ($parentPid === null) {
            // Only a record that was never persisted has no pid, and a new
            // parent cannot own the persisted children this method diffs
            // against. Refusing is what keeps "the child pid comes from the
            // parent record" a fact rather than an intention: the alternative
            // is to skip the assignment and let Extbase fall back to
            // `persistence.storagePid`.
            throw new \InvalidArgumentException(
                'The parent record has no pid and cannot own children.',
                1786493012
            );
        }

        // Detach everything first. This is what resets the position counter to
        // `0`, and it is why the re-attached members come out as `1..n`.
        foreach ($collection->toArray() as $child) {
            $collection->detach($child);
        }

        foreach ($intended as $child) {
            if ($child->_isNew()) {
                $child->setPid($parentPid);
            }

            $collection->attach($child);
        }

        return $this->orphans($intended, $owned);
    }

    /**
     * The owned children that the intended set no longer contains.
     *
     * A child of `$owned` without a uid is skipped: it was never persisted,
     * so there is no row to delete and `Backend::removeEntity()` would build an
     * update with `uid => null`.
     *
     * @template T of AbstractEntity
     * @param ObjectStorage<T> $intended
     * @param list<T> $owned
     * @return list<T>
     */
    private function orphans(ObjectStorage $intended, array $owned): array
    {
        $intendedUids = [];
        foreach ($intended as $child) {
            $uid = $child->getUid();
            if ($uid !== null) {
                $intendedUids[$uid] = true;
            }
        }

        $orphans = [];
        foreach ($owned as $child) {
            $uid = $child->getUid();
            if ($uid === null || isset($intendedUids[$uid])) {
                continue;
            }

            $orphans[] = $child;
        }

        return $orphans;
    }
}
