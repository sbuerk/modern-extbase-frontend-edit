# Persistence and sorting

Frontend edits are written through the Extbase `PersistenceManager`, not through
`DataHandler`. This page records that decision and what it costs, and then
spends most of its length on the part that actually needs writing down: what
Extbase does **not** do for us, and what we therefore implement by hand.

Citations are package-relative paths inside `typo3/cms-extbase`, or prefixed
`cms-core/` for `typo3/cms-core`, with the core version stated wherever the two
differ. v14 numbers come from the installed set below `.Build/vendor/`, v13
numbers from 13.4.34. Where no version is named, the code is the same in both.

> [!NOTE]
> **Both sides of this page are code now.** The display and edit repositories in
> `Classes/Domain/Repository/` cover the read side. The write side is
> `Classes/Domain/Persistence/`: `ChildCollectionSynchronizer` does the reorder,
> the child `pid` and the orphan diff on the object graph and touches no
> repository; `ProfilePersistenceService` is the single place in the extension
> that calls `update()`, `remove()` or `persistAll()`; `WorkspaceGuard` refuses
> while a workspace is active. The checklist at the end of this page is complete:
> its last open item, the **file cleanup**, landed with the image upload as
> `UnreferencedFileCleanupService` and the two image methods of
> `ProfilePersistenceService` — see [Image handling](image-handling.md).

## The decision: `PersistenceManager`, not DataHandler

`DataHandler` is the backend editing API. Driving it from a frontend request
means handing it a data map of raw field values keyed by table and uid, and
giving it a backend user to run its permission checks against — either a real
one, or a synthetic one whose rights we then have to constrain ourselves. That
replaces our authorization layer with a second, weaker one, and it introduces a
second mapping between our domain models and the database next to the one
Extbase already maintains.

The editing surface here is small and fully specified: a known set of properties
on our own tables, guarded by an ownership rule and a validation rule set that
run *before* anything is written. For that shape, the Extbase persistence layer
is the right tool, and the one that already knows our models.

What we give up by not using `DataHandler` is real, and accepted:

| Given up                            | What that means in practice                                                                                                                                                                                                                                                   |
|-------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `sys_history`                       | Frontend edits leave no history entry and cannot be undone from the backend. v14.2 can record Extbase writes (Feature #107289), but the feature toggle `extbase.enableHistoryTracking` is **off by default** and the feature does not exist on v13 — so we cannot rely on it. |
| DataHandler hooks and PSR-14 events | Nothing that listens on `processDatamap_*` or the DataHandler events sees a frontend edit. Extensions that enforce invariants there are bypassed. Ours must not depend on them.                                                                                               |
| Reference index maintenance         | v13 updates `sys_refindex` only when `plugin.tx_*.persistence.updateReferenceIndex` is set (`Backend.php:589`, v13, default off); v14 always updates it (Breaking #106041). The index is therefore **stale on v13** after a frontend edit unless the site opts in.            |
| Versioning                          | Writes go to the live row, always. See [Workspaces](#workspaces-the-guard-is-load-bearing).                                                                                                                                                                                   |
| Translation handling                | No `l10n_parent` linkage can be created. See [Languages](#languages-no-translation-creation).                                                                                                                                                                                 |
| Sorting, orphans, hidden            | All of it is ours. That is the rest of this page.                                                                                                                                                                                                                             |

## How a collection is written at all

`$repository->update($profile)` followed by `persistAll()` reaches
`Backend::commit()` → `persistObject()`. An `ObjectStorage` property is only
looked at when the parent is new, the storage reports itself dirty, or the
storage was emptied:

```php
// v14: Classes/Persistence/Generic/Backend.php:281  (v13: :252, identical)
if ($object->_isNew() || $propertyValue->_isDirty() || ($propertyValue->count() === 0 && $cleanProperty && $cleanProperty->count() > 0)) {
    $this->persistObjectStorage($propertyValue, $object, $propertyName, $row);
```

`persistObjectStorage()` (v14 `Backend.php:339`, v13 `:309`) then detaches
removed children, inserts new ones and attaches each member with a sorting
position. **Without the `update()` call on the parent repository the aggregate
never enters `changedEntities` and none of this runs** — modifying the object
graph alone persists nothing.

## Manual sorting

This is the load-bearing part of the whole feature, so it is worked through in
full.

### Extbase does write the sorting column

It happens in exactly one place on the attach path:

```php
// v14: Classes/Persistence/Generic/Backend.php:481-484  (v13: :457-460)
$childSortByFieldName = $parentColumnMap->childSortByFieldName;
if (!empty($childSortByFieldName)) {
    $row[$childSortByFieldName] = $sortingPosition;
}
if (!empty($row)) {
    $this->updateObject($object, $row);
}
```

The two versions differ only in property access — v14 reads the public readonly
`ColumnMap` property, v13 calls `getChildSortByFieldName()`. The behaviour is
identical.

### The four conditions, each of which can silently lose sorting

| Condition                                            | Where it is decided                                                                                  | If it does not hold                                                        |
|------------------------------------------------------|------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------|
| The **parent** column's TCA carries `foreign_sortby` | `ColumnMapFactory.php:130` (v14) / `:196` (v13)                                                      | `childSortByFieldName` is `null`, and no sorting value is ever written.    |
| The relation is `HAS_MANY`                           | `Backend::attachObjectToParentObject()` dispatches only for `HAS_MANY` and `HAS_AND_BELONGS_TO_MANY` | An `IllegalRelationTypeException` (1345368105) or no sorting write at all. |
| The storage reports itself dirty                     | `Backend.php:281` (v14), guarded by `ObjectStorage::_isDirty()`                                      | `persistObjectStorage()` is never called and nothing is written.           |
| The computed positions actually differ               | the loop at `Backend.php:360-389` (v14), comparing against the clean clone                           | Every member keeps the value it already had in the database.               |

The first one is the trap that looks solved and is not: **`ctrl.sortby` on the
child table is invisible to the Extbase write path.** Extbase only ever reads
`foreign_sortby` from the parent column configuration:

```php
// v14: Classes/Persistence/Generic/Mapper/ColumnMapFactory.php:130
childSortByFieldName: $columnConfiguration['foreign_sortby'] ?? null,
```

We need **both**: `ctrl.sortby => 'sorting'` on the child table so the backend
sorts and drag-drops it manually, and `foreign_sortby => 'sorting'` on the
parent's collection column so Extbase writes it. Neither substitutes for the
other.

`_isDirty()` is worth one sentence as well, because it is coarser than it looks:
it is a plain boolean flag set by `offsetSet()` and `offsetUnset()`
(`ObjectStorage.php:150`, `:175`), i.e. by `attach()`/`detach()` only. Changing
a property *on* a contained child never marks the storage dirty — which is
correct, and which is also why a reorder must go through attach/detach to be
seen at all.

### The trap: `attach()` on a contained object reorders nothing

```php
// v14 and v13: Classes/Persistence/ObjectStorage.php:148-155
public function offsetSet(mixed $object, mixed $information): void
{
    $this->isModified = true;
    $this->storage[spl_object_hash($object)] = ['obj' => $object, 'inf' => $information];

    $this->positionCounter++;
    $this->addedObjectsPositions[spl_object_hash($object)] = $this->positionCounter;
}
```

The storage is a PHP array keyed by `spl_object_hash()`. Writing an **existing**
key updates the value in place — PHP keeps the element at its current array
position. So `attach()` on an object that is already contained bumps
`positionCounter` and marks the storage dirty, but iteration order is unchanged,
and the sorting values Extbase then writes are the *old* order. The operation
looks like it worked, and produces a wrong database.

`ObjectStorage` has no `sort()`, `move()` or `setOrder()`. The only way to
reorder it is to empty it and refill it.

### The reorder recipe

Detaching *all* members resets the position counter:

```php
// v14 and v13: Classes/Persistence/ObjectStorage.php:183-187
unset($this->storage[spl_object_hash($object)]);

if (empty($this->storage)) {
    $this->positionCounter = 0;
}
```

which is what makes the re-attached positions come out as `1..n` and line up
with the `$sortingPosition` counter in `persistObjectStorage()`. The shape to
review the implementation against:

```php
// Reorder $profile->getAddresses() into $orderedUids.
$storage = $profile->getAddresses();

$byUid = [];
foreach ($storage as $address) {
    $byUid[$address->getUid()] = $address;
}

// Detach all -> positionCounter resets to 0.
foreach ($storage->toArray() as $address) {
    $storage->detach($address);
}

// Re-attach in target order -> positions 1..n.
foreach ($orderedUids as $uid) {
    $storage->attach($byUid[$uid]);
}

$profileRepository->update($profile);
$persistenceManager->persistAll();
```

Two things about that snippet are deliberate. `toArray()` is iterated rather
than the storage itself, because detaching while iterating the live storage
mutates what is being iterated. And `$orderedUids` must be validated against
`$byUid` beforehand — a uid that is not a member is a client error, not a
missing key to be silently skipped.

The functional test for this is shown to fail before it is trusted: reorder,
persist, assert the `sorting` column, then remove `foreign_sortby` from the TCA
and watch it go red.

### The numbering is compatible with the backend

Extbase writes dense `1, 2, 3, …` over the members of the storage. The backend
does the same for inline children — `RelationHandler::writeForeignField()` uses
`$updateValues[$sortby] = ++$c;`
(`cms-core/Classes/Database/RelationHandler.php:1060`). Frontend and backend
writes are therefore interchangeable: a record reordered in the frontend can be
reordered again in the backend without a renumbering step, and vice versa.

`DataHandler::$sortIntervals = 256` does **not** apply here — it governs
table-level `ctrl.sortby` moves, not inline `foreign_sortby` children.

What Extbase never does: renumber with gaps, resolve collisions, or touch rows
that are not members of the storage. And a detached child gets `sorting = 0`
(`Backend.php:511-512`, v14 / `:490-492`, v13), which means an orphan sorts
*first* in any query that orders by that column without filtering on the parent.

## Orphans are ours to delete

Removing a child from the storage only unwires it:

```php
// v14: Classes/Persistence/Generic/Backend.php:500-513 (v13: :476-492), abridged
if ($parentColumnMap->typeOfRelation === Relation::HAS_MANY) {
    $row = [];
    if ($parentColumnMap->parentKeyFieldName !== null) {
        $row[$parentColumnMap->parentKeyFieldName] = 0;
        // + foreign_table_field = '' and the match fields
    }
    if (!empty($parentColumnMap->childSortByFieldName)) {
        $row[$parentColumnMap->childSortByFieldName] = 0;
    }
```

The row survives: `deleted = 0`, `hidden` untouched, parent pointer `0`,
`sorting = 0`, `pid` unchanged. It is invisible in the backend because it has no
parent, it still occupies the reference index, and nothing will ever clean it
up.

### Why `#[Cascade('remove')]` is not used

The obvious fix is `#[Cascade('remove')]` on the collection property, which
makes `persistObjectStorage()` call `removeEntity()` after the detach. It is
rejected for three independent reasons:

| Reason                                | Detail                                                                                                                                                                                                                                                                                              |
|---------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| No version-neutral spelling           | v13 is `Annotation\ORM\Cascade::__construct(array $values)` — `#[Cascade('remove')]` is a `TypeError`. v14 accepts the string and raises `E_USER_DEPRECATED` for the array form. Every spelling either fatals on v13 or deprecates on v14, and this repository fails a test run on any deprecation. |
| It does not cover the file reference  | On a `HAS_ONE` property the cascade only runs when the **parent** is removed (`Backend.php:829-836`, v14). Setting the property to `null` or replacing it writes at most `0` into the parent column, so the old `sys_file_reference` row is orphaned regardless.                                    |
| It is silently skipped when `#[Lazy]` | A lazy `HAS_ONE` holds a `LazyLoadingProxy`, which implements `\Iterator` and `LoadingStrategyInterface` but **not** `DomainObjectInterface` — the `elseif` that triggers the cascade is simply false, with no error.                                                                               |

So: no `#[Cascade]` anywhere in the models, and the edit service calls
`$repository->remove($child)` explicitly for every child taken out of a
collection, and for every replaced or cleared file reference, **before**
`persistAll()`. `remove()` soft-deletes when the table has `ctrl.delete` and
issues a hard `DELETE` otherwise (`Backend.php:794-812`, v14).

The version split of the attribute itself is not specific to `#[Cascade]` — two
further Extbase attributes have the same problem, and they are collected in
[Version neutral attributes](../architecture/version-neutral-attributes.md).

Deleting the physical file behind a replaced reference is a separate concern
with its own rule — delete only when nothing except our own reference row points
at the file — and is covered in
[Image handling](image-handling.md#the-cleanup-and-the-safeguard-that-makes-it-safe),
where the earlier wording of that rule is corrected as well.

## Hidden children never arrive through the parent

The class docblock of `Typo3QuerySettings` says it outright:

```php
// v14 and v13: Classes/Persistence/Generic/Typo3QuerySettings.php:32-34
 * It is possible for each Query to have a dedicated Typo3QuerySettings object, but those settings
 * are not adhered to when reconstituting relations of entity objects. There a completely new
 * Typo3QuerySettings object is used, with default settings applied.
```

and the code confirms it — relations are loaded through a query built from
scratch:

```php
// v14: Classes/Persistence/Generic/Mapper/DataMapper.php:484-494  (v13: :454-…)
protected function getPreparedQuery(DomainObjectInterface $parentObject, $propertyName, $fieldValue = '')
{
    …
    $query = $this->queryFactory->create($type);
    …
    $query->getQuerySettings()->setRespectStoragePage(false);
    $query->getQuerySettings()->setRespectSysLanguage(false);
```

`ignoreEnableFields` is never set there, so it stays at the context default —
`false` in the frontend. **The edit repository's query settings do not reach the
children.** Hidden `Address` and `Email` rows are therefore absent from
`$profile->getAddresses()` even inside the edit plugin, no matter how the
`Profile` was fetched.

The specification requires hidden relation records to be visible in the edit
plugin and toggleable there. The consequence is structural, and this is the
sentence that must survive a future round of simplification:

> **Children are loaded through their own edit repositories, with their own
> query settings, and assembled by the edit service. The edit flow does not read
> collections off the parent aggregate.**

The parent's storage is still used for *writing* — that is where sorting and the
parent pointer come from — but the set of children shown in the form is built by
querying `Address`/`Email` by parent uid with the edit settings applied. Reading
them off `$profile` instead is a one-line "cleanup" that produces a form
silently missing exactly those records the user disabled — and no test whose
fixtures contain only visible children will notice.

The alternative, listening on `ModifyQueryBeforeFetchingObjectDataEvent` to
patch the relation query, was rejected: it is a global listener that would have
to guess from the query which request context it is in, and it changes relation
loading for every extension in the installation.

## `findByUid()` bypasses the default query settings

```php
// v14: Classes/Persistence/Generic/Backend.php:162-188  (v13: :140), abridged
public function getObjectByIdentifier($identifier, $className)
{
    $query = $this->persistenceManager->createQueryForType($className);
    …
    $query->getQuerySettings()->setLanguageAspect($languageAspect);
    $query->getQuerySettings()->setRespectStoragePage(false);
    $query->getQuerySettings()->setRespectSysLanguage(false);
    return $query->matching($query->equals('uid', $identifier))->execute()->getFirst();
}
```

The query is created fresh, so `setDefaultQuerySettings()` on the repository has
no effect on it — including `ignoreEnableFields`, which is not touched and stays
`false` in the frontend. A hidden `Profile` is **not** findable through
`findByUid()`.

The edit repositories therefore carry their own lookup, built from
`createEditQuery()` rather than from the inherited finders:

```php
public function findByUidIncludingHidden(int $uid): ?Profile
{
    $query = $this->createEditQuery();
    return $query->matching($query->equals('uid', $uid))->execute()->getFirst();
}
```

The child repositories carry the **owner constrained** variant of the same shape,
`findByUidAndProfileUidIncludingHidden(int $uid, int $profileUid)`, whose second
constraint comes from the profile the session already resolved. The client uid is
one half of the `logicalAnd()` and never the whole of it, which is what makes a
foreign child uid match nothing instead of matching a row that is then inspected.

This is separate from the rule that the AJAX controller resolves the profile
from the session rather than from a client-supplied uid — that rule stands, and
these lookups are used on the already-owned set.

## The display and edit repository split

Two repositories over the same table, differing in the query settings the edit
one relaxes **per query**, in a protected `createEditQuery()` on
`AbstractEditRepository` — deliberately not in `initializeObject()` through
`setDefaultQuerySettings()`.

| Repository | Query settings                                                                   | Sees                                     |
|------------|----------------------------------------------------------------------------------|------------------------------------------|
| Display    | defaults, written out explicitly                                                 | visible records only                     |
| Edit       | `setIgnoreEnableFields(true)` **and** `setEnableFieldsToBeIgnored(['disabled'])` | hidden records too, nothing else relaxed |

The per-query placement is the load-bearing half of that, for two reasons.
`setDefaultQuerySettings()` makes `createQuery()` clone the relaxed object for
**every** query the repository will ever build — `findAll()`, `findByUid()` and
the inherited `findBy*()` magic included, none of which take an owner constraint
— so nothing but a docblock would stand between a future caller and a result set
of other people's disabled records. With the relaxation on `createEditQuery()`,
the inherited finders stay visible-only and a hidden record can only be reached
by writing a method that says so. Second, `setDefaultQuerySettings()` freezes
what `QueryFactory::create()` resolved when the shared repository was first
instantiated, `persistence.storagePid` included, for the rest of the request.

The second call is not optional. `setIgnoreEnableFields(true)` **alone** takes
the `else` branch of `getFrontendConstraintStatement()` and reduces the whole
constraint to `deleted = 0` — dropping `starttime`, `endtime` and `fe_group`
along with `hidden`:

```php
// v14: Classes/Persistence/Generic/Storage/Typo3DbQueryParser.php:691-717, abridged
if ($ignoreEnableFields && !$includeDeleted) {
    if (!empty($enableFieldsToBeIgnored)) {
        $constraints = $pageRepository->getDefaultConstraints($tableName, $enableFieldsToBeIgnored, $tableAlias);
        …
    } else {
        // only "<alias>.deleted = 0"
    }
```

With `enableFieldsToBeIgnored` set, the call is routed through
`PageRepository::getDefaultConstraints()`
(`cms-core/Classes/Domain/Repository/PageRepository.php:1499`), which keeps
`deleted`, the workspace constraints, `starttime`, `endtime` and `fe_group`, and
skips only the `disabled` column. `includeDeleted` stays `false` — in the
frontend it would additionally require `ignoreEnableFields`, and combining the
two throws `InconsistentQuerySettingsException` (1460975922) anyway.

`QuerySettingsInterface` and `Typo3QuerySettings` are byte-identical between
13.4.34 and 14.3, so this split needs no version handling.

Toggling `hidden` itself has no Extbase API. It needs a `hidden` property on the
model plus a matching TCA `columns` entry, so that `DataMapFactory` builds a
`ColumnMap` for it; then `persistObject()` writes it like any other scalar.

## Workspaces: the guard is load-bearing

The storage backend issues plain DBAL statements against the live row:

```php
// v14: Classes/Persistence/Generic/Storage/Typo3DbBackend.php:84 and :114
public function addRow(string $tableName, array $fieldValues, bool $isRelation = false): int
public function updateRow(string $tableName, array $fieldValues, bool $isRelation = false): void
```

No `t3ver_wsid`, no `t3ver_oid`, no versioning, no `DataHandler`. Editing while
a workspace is active does not create a workspace version — it **modifies the
live record**, while the editor believes they are working in a draft. Refusing
the write is the only correct behaviour, and the guard is therefore functional,
not defensive: without it the feature silently corrupts published content.

### The refusal is now visible before anything is typed

The guard has always answered `409`. What changed is *when* the visitor learns
of it: `ProfileEditController` asks the same `WorkspaceGuard` and renders the
record **read only** in a workspace — no custom element, neither asset, and no
request token — under a sentence saying editing is live only.

That is the whole of the change, and it is worth being clear about what it is
not. It does not make workspace editing work, and nothing here is a step towards
it: versioning a record is `DataHandler`'s job, and
[this extension deliberately does not use `DataHandler`](#the-decision-persistencemanager-not-datahandler).
A limitation the user runs into after typing reads as a bug; the same limitation
stated up front is a limitation. The `409` stays as the backstop — the surface is
not a security boundary, and a client that posts anyway still gets refused.
→ [The edit plugin's four states](edit-plugin.md)

Detection is one aspect read from the injected `Context` — never `$GLOBALS`:

```php
if (!$this->context->getPropertyFromAspect('workspace', 'isLive', true)) {
    throw new WorkspaceWritesNotSupportedException(…, <code>);
}
```

`WorkspaceAspect::isLive()` is `$this->workspaceId === 0` and the class is
byte-identical in both versions. Core uses the same signal in the same area —
`Typo3DbQueryParser` reads `workspace/isOffline`, `PageRepository` reads
`workspace/id`, and `RequestHandler::getClientCacheHeaders()` reads
`workspace/isLive` with exactly the same `true` default
(`cms-frontend/Classes/Http/RequestHandler.php:1221`).

### Correction: what the `true` default actually covers

An earlier revision of this page justified that default with "an absent aspect
must read as live, so a missing aspect cannot disable the guard". That reasoning
is wrong, and it is worth replacing rather than deleting, because it is the
reasoning anyone will reach for again.

**An absent `workspace` aspect cannot happen.** `Context::hasAspect()` answers
`true` for it unconditionally:

```php
// cms-core/Classes/Context/Context.php:64-70
public function hasAspect(string $name): bool
{
    return match ($name) {
        'date', 'visibility', 'backend.user', 'frontend.user', 'workspace', 'language' => true,
        default => isset($this->aspects[$name]),
    };
}
```

and `getAspect()` lazily instantiates `new WorkspaceAspect()` when none was set
(`Context.php:83-105`), whose `$workspaceId` defaults to `0` — live. The
`AspectNotFoundException` branch of `getPropertyFromAspect()` (`Context.php:120-122`)
is therefore **unreachable for this aspect name**, and the default is never
consulted on account of a missing aspect.

What the default does cover is one case, and it is a narrow one: a
`AspectPropertyNotFoundException` from `get()`, which is the only exception
`getPropertyFromAspect()` catches (`Context.php:123-127`).
`WorkspaceAspect::get()` handles `id`, `isLive` and `isOffline` and throws
`1527779447` for anything else (`WorkspaceAspect.php:41-52`), so reaching the
default means **somebody replaced the aspect** with a class that does not
implement the documented contract.

That is not a hypothetical worth removing the default for — replacing the
workspace aspect is exactly what a preview or a simulation extension does — but
it does change what the default is *for*. It is a fail-live answer to a
non-conforming aspect, not a guard against an unset one. The comparison is
`=== true` rather than a truthiness check for the same reason: a replacement
aspect returning a non-boolean must not pass as "live" by accident.

## Languages: no translation creation

`insertObject()` hardcodes the language columns:

```php
// v14: Classes/Persistence/Generic/Backend.php:565-575  (v13: :546-551)
if ($dataMap->languageIdColumnName !== null && $object->_getProperty(AbstractDomainObject::PROPERTY_LANGUAGE_UID) === null) {
    $row[$dataMap->languageIdColumnName] = 0;
    $object->_setProperty(AbstractDomainObject::PROPERTY_LANGUAGE_UID, 0);
}
if ($dataMap->translationOriginColumnName !== null) {
    $row[$dataMap->translationOriginColumnName] = 0;
}
```

Every record created through this path gets `sys_language_uid = 0` and
`l10n_parent = 0`, regardless of the site language of the request.
`updateObject()` writes `sys_language_uid` from the model, but **never**
`l10n_parent` — so the best that can be produced is a free-mode record, not a
translation.

This is a gap, not a solved problem: **creating a real, `l10n_parent`-linked
translation is not possible through Extbase persistence.** Doing it would
require `DataHandler`, which this design rules out. Editing is restricted to
the default language, the restriction is enforced rather than assumed, and it is
documented for integrators.

The core changelog states the same thing from the other side, in
`14.3.x/Important-88886-ExtbasePersistenceRespectsLanguageOverlayType.rst`:
records created through Extbase in the frontend are persisted with
`sys_language_uid=0` unless a language is explicitly assigned, and on sites
using `fallbackType: strict` they are not visible in translated languages until
they have been translated.

## v14 behaviour changes worth knowing

Both are v14-only and neither needs version-split code, but both change what a
test may observe.

| Change                                                           | Effect                                                                                                                                                                                                                                                                                                                                                                                                                     |
|------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Important #88886, 14.3.x — persistence respects the overlay type | Extbase used to apply mixed overlay semantics always. It now follows the site language's `fallbackType`. With `fallbackType: strict`, `findByUid()` on an untranslated record returns `null` and untranslated children are filtered out of relations. Display repositories must be tested per fallback type; the changelog documents how to restore the old behaviour per query with an `OVERLAYS_MIXED` `LanguageAspect`. |
| Important #93765, 14.2 — language-aware identity map             | `Session::buildIdentifier()` (`Session.php:170`) now includes `contentId`, `overlayType` and `fallbackChain`. Objects loaded under different language settings are distinct instances, so `===` comparisons across query settings no longer hold.                                                                                                                                                                          |

## What we implement by hand

Everything in this table is behaviour `DataHandler` would have provided and the
Extbase persistence layer does not. It was the acceptance checklist for the
implementation; it is now the map of where each item ended up.

**Every row is done.** The last one, the file cleanup, landed with the image
upload.

| Item                               | Why Extbase does not do it                                                                                         | What we build, and where it lives                                                                                     |
|------------------------------------|--------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------|
| Reordering a collection            | `ObjectStorage` has no reorder API; `attach()` on a contained object keeps its array position                      | `ChildCollectionSynchronizer::synchronize()` — detach-all, then re-attach in the intended order                       |
| Writing `sorting` at all           | Only `foreign_sortby` on the parent column feeds `childSortByFieldName` (`ColumnMapFactory.php:130`)               | `foreign_sortby` on both collection columns, next to `ctrl.sortby` on the child tables                                |
| Deleting orphans                   | Detach clears the parent pointer and sets `sorting = 0`; the row survives (`Backend.php:500-513`)                  | `synchronize()` returns the dropped children; `ProfilePersistenceService` calls `remove()` per child before the flush |
| Deleting a replaced file reference | `#[Cascade]` on `HAS_ONE` only runs when the parent is removed, and is skipped entirely for `#[Lazy]`              | `ProfilePersistenceService::saveProfileImage()`/`removeProfileImage()`, guarded by `UnreferencedFileCleanupService`   |
| Loading hidden children            | `DataMapper::getPreparedQuery()` builds fresh default query settings for relations                                 | `findAllByProfileUid()` on the child edit repositories; the parent's collection is never read for display             |
| Finding a hidden record by uid     | `Backend::getObjectByIdentifier()` builds a fresh query and ignores `defaultQuerySettings`                         | `findByUidIncludingHidden()` and the owner constrained `findByUidAndProfileUidIncludingHidden()`                      |
| Showing hidden but not expired     | `setIgnoreEnableFields(true)` alone drops `starttime`/`endtime`/`fe_group` as well                                 | `setEnableFieldsToBeIgnored(['disabled'])` alongside it, with `includeDeleted` left `false`                           |
| Toggling `hidden`                  | No API; the column is only writable if it is a mapped property                                                     | A `hidden` property, its TCA `columns` entry, a setter, and one endpoint — **for children only**                      |
| Refusing workspace writes          | Writes are plain `insert`/`update` on the live row, entirely workspace-blind                                       | `WorkspaceGuard`, asserted by the controller *and* at the entry of every write method of the persistence service      |
| Language handling                  | `INSERT` always writes `sys_language_uid = 0` and `l10n_parent = 0`                                                | Default-language-only editing, enforced and documented as a limitation                                                |
| The `pid` of new records           | `determineStoragePageIdForNewRecord()` prefers the object's own pid over all configuration (`Backend.php:855-882`) | `synchronize()` assigns `$parent->getPid()` to new children only, gated by `_isNew()`; `pid` is never a DTO property  |

The workspace row is asserted twice on purpose. The controller's call is what
turns a refusal into a clean `409` instead of an exception page; the one in
`ProfilePersistenceService` is what makes the rule impossible to bypass by adding
a second caller. It is one rule read from one `Context`, not two copies that can
drift.

## What the write path does not do

Three gaps, stated here rather than discovered later. None of them is a bug in
the sense of "the code does not do what it says"; each is a property of the
chosen approach that a reader has to know about.

### `persistAll()` is not a transaction

There is no transaction anywhere in this write path, and the Extbase storage
backend offers none. `Backend::commit()` runs `persistObjects()` and then
`processDeletedObjects()` (`Backend.php:229-233`), issuing a sequence of
independent `INSERT`, `UPDATE`
and soft-delete statements through `Typo3DbBackend`. **A failure part way through
leaves a partially written aggregate** — for example a reorder in which some
children carry their new `sorting` and some do not, or a removal in which the
detach was written and the soft-delete was not.

That is accepted for this editing surface, and it is why every write method of
`ProfilePersistenceService` flushes **exactly once**: the window is then as small
as the API allows, and a full record save is one flush rather than three. Wrapping
the flush in a DBAL transaction of our own was considered and rejected here — it
would have to span the connection Extbase picks internally, and getting that
half-right is worse than the honest statement.

### Densification is not repaired

Extbase writes dense `1..n` over a collection it renumbers, but it only writes at
all for a new child, a child the clean storage does not know, or a child whose
position moved (the loop at `Backend.php:360-389`). **A collection whose order
does not change is therefore not renumbered**, and pre-existing gaps in the
`sorting` column survive — a row reordered in the backend with
`DataHandler::$sortIntervals`, or a row whose neighbour was deleted outside this
extension.

The gaps are harmless: every read orders *by* the column and never trusts its
values, and a frontend reorder rewrites the whole collection densely. What is not
available is "open the editor and the sorting is tidied up". Repairing it would
mean writing every member on every save, which turns a no-op save into `n`
`UPDATE` statements for a cosmetic property of the data.

### The profile's own `hidden` flag is not writable

`setChildVisibility` is the only endpoint that reaches the `hidden` column, and
its name is literal — it addresses a child. The profile's own flag is in every
response so an editor can show the state, and no endpoint changes it.

That is deliberate rather than an oversight. Publishing or unpublishing a whole
profile is a different decision from hiding one of its e-mail addresses: it needs
a rule about who may make a record public, and possibly a moderation step, and
that rule is not written. Shipping the column as writable "for symmetry" would
ship the missing rule with it.

## See also

- [Modern frontend editing](Index.md) — the other pages of this design.
- [AJAX transport](ajax-transport.md) — the nine endpoints that drive this
  write path, and what each of them hands to it.
- [Domain and schema](domain-schema.md) — where `ctrl.sortby` and
  `foreign_sortby` are actually configured, and the rest of the TCA.
- [Image handling](image-handling.md) — the file reference, its replacement and
  the cleanup rule this page defers to.
- [Authorization](authorization.md) — why the uid lookup here is only ever used
  on an already-owned set.
- [Version neutral attributes](../architecture/version-neutral-attributes.md) —
  the `#[Cascade]` split, and the two attributes with the same problem.
- [Class design](../architecture/class-design.md) — `final readonly` services,
  and why models and DTOs are data objects rather than services.
- [Site based tests](../testing/site-based-tests.md) — needed for the
  `fallbackType` matrix of Important #88886.
- [Dual core setup](../development/dual-core-setup.md) — reading the changelogs
  of both majors from disk.
- `.Build/vendor/typo3/cms-core/Documentation/Changelog/` — the authoritative
  text of every changelog entry cited above.
