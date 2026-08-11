# Image handling

A profile carries exactly one image. It is nullable — "no image" is a valid
state, not an error — it is uploaded through the modern Extbase upload API
(Feature #103511, v13.3), it can be replaced by uploading again, and our own
model covers it so that templates and JSON responses speak our vocabulary
rather than the framework's.

Three of those four are free. The fourth is not, and this page records what it
costs and what was decided instead.

Code paths quoted below are relative to `.Build/vendor/typo3/` and refer to the
installed set, which is TYPO3 v14.3, unless a v13.4 line is named explicitly.
**The read side of this page exists; the upload side does not.**
`Profile::$image` is the framework `FileReference`, `Profile::getProfileImage()`
derives the scalar-only value object `Domain\Model\ProfileImage` from it, and
`Resources/Private/Partials/Profile/Image.html` renders that. Everything that
*writes* — `FileUploadConfiguration` in an `initialize<Action>()`, replacement,
and the reference-counted cleanup of the previous file — follows in a later
change, and the sections describing it say so.

## A custom `FileReference` subclass is impossible

The modern upload API decides what it will handle by comparing class names for
identity — `!==` and `===`, never `instanceof` and never `is_a()`. Any subclass
of `\TYPO3\CMS\Extbase\Domain\Model\FileReference` therefore fails all three
checks, on **both** supported versions:

| Check                                                            | v14.3  | v13.4  | What a subclass gets                                                              |
|------------------------------------------------------------------|--------|--------|-----------------------------------------------------------------------------------|
| `cms-extbase/Classes/Service/FileHandlingService.php`            | `:130` | `:128` | The property is skipped during discovery — no configuration, no upload, no error. |
| `cms-extbase/Classes/Service/FileHandlingService.php`            | `:236` | `:234` | The mapping branch is not taken — the uploaded file is discarded.                 |
| `cms-extbase/Classes/Mvc/Controller/FileUploadConfiguration.php` | `:179` | `:179` | `\RuntimeException` 1721623184 from `ensureValidConfiguration()`.                 |

The value compared is the **declared PHP type** of the model property, read from
the `ClassSchema` (`FileHandlingService.php:104-127` and `:227-234`). Configuring
the upload manually does not help: check three fires from
`ensureValidConfiguration()`, and check two silently skips the mapping.

An `XCLASS` is not a way around it either. The instance is created with
`GeneralUtility::makeInstance(FileReference::class)`
(`FileHandlingService.php:452`), so an XCLASS *would* be honoured — but the
property still has to be *declared* as the base class for the checks above to
pass, and `ObjectAccess::setProperty()` (`:297`) would raise a `TypeError`
against a narrowed `?MyFileReference` declaration.

The persisted property is therefore, literally:

```php
protected ?\TYPO3\CMS\Extbase\Domain\Model\FileReference $image = null;
```

### The custom model becomes a read-side wrapper

This is the better half of the trade, not a consolation prize, and the reason is
that the two halves were never wanted for the same thing.

**Reading is where the custom model earns its keep.** Without it, a template
reaches through `getOriginalResource()->getOriginalFile()` to get at a public
URL, and a JSON response has to assemble the same chain again in a controller.
That chain is framework vocabulary leaking into two layers that should not know
it, and it is untestable without a storage. A `final readonly` value object
built from the `FileReference` fixes both, and it is the shape a DTO needs
anyway — plain scalars, serializable, no FAL object graph behind it.

**Writing never wanted it.** The upload service constructs the instance itself,
assigns the file through `setOriginalResource()`, and the row it produces is a
`sys_file_reference` row either way. A subclass would add exactly one thing to
the write path: an entry in `Configuration/Extbase/Persistence/Classes.php`
mapping it back to `sys_file_reference`, whose only job would be to undo the
table name `DataMapFactory::resolveTableName()` derives from the class name
(`cms-extbase/Classes/Persistence/Generic/Mapper/DataMapFactory.php:166-177`).
That is configuration bought at the price of a class, in exchange for nothing.

So the wrapper is **derived, never stored**:

| Property                                     | Where it lives                                                       |
|----------------------------------------------|----------------------------------------------------------------------|
| The persisted relation                       | `?FileReference` on the Extbase model — the exact framework class.   |
| The value object handed to Fluid and to DTOs | Built on demand from that reference, `final readonly`, `#[Exclude]`. |

Derived rather than stored matters for a second reason: a stored transient
property would need `#[Transient]`, which is a **fourth** Extbase attribute
without a version-neutral spelling (`Annotation\ORM\Transient` on v13.4,
`Attribute\ORM\Transient` on v14.3). A getter that builds the value object needs
no attribute at all.
→ [Version neutral Extbase attributes](../architecture/version-neutral-attributes.md)

## Uploads are configured in `initialize<Action>()`

`#[FileUpload]` is not used. It has no spelling that is correct on both
versions, and its failure mode on v13 is the worst kind:

| Spelling                                | v13.4                                 | v14.3               |
|-----------------------------------------|---------------------------------------|---------------------|
| `\Extbase\Attribute\FileUpload`         | **silently does nothing**             | correct             |
| `\Extbase\Annotation\FileUpload`, array | correct                               | `E_USER_DEPRECATED` |
| Named arguments                         | `Unknown named parameter $validation` | correct             |

The first row is the dangerous one. v13.4 has no `Attribute\` namespace at all,
and its `ClassSchema` matches the `Annotation\` name only — an attribute written
with the v14 FQCN falls into `default => ''`
(`cms-extbase/Classes/Reflection/ClassSchema.php:178-186` on the v13.4 branch;
v14.3 accepts both names at `:145-152`). `Property::getFileUpload()` then stays
`null`, and `FileHandlingService` skips the property at `:128`. No exception, no
warning, no log entry: the form submits, the record saves, and the image is
simply absent. A wrong FQCN that throws is a bug found in ten seconds; this one
is found by a user.

The array form works on both, but v14 answers it with `E_USER_DEPRECATED`, and
this repository's suites fail on a deprecation.

The way out is the PHP API, which is **byte-identical between the two versions**
apart from a doc comment (`FileUploadConfiguration.php`,
`FileHandlingServiceConfiguration.php` and `FileUploadDeletionConfiguration.php`
were diffed against the v13.4 branch). It is also the only route to a custom
validator — the attribute's `validation` array understands a fixed set of keys
and silently ignores everything else.

`initializeFileUploadConfigurationsFromRequest()` runs at
`cms-extbase/Classes/Mvc/Controller/ActionController.php:368`, **before**
`initializeAction()` and `initialize<Action>()`, and argument values are mapped
afterwards at `:376`. That ordering is what makes the manual hook work:
`initialize<Action>()` sees the configuration and never the data.

```php
public function initializeUpdateAction(): void
{
    $argument = $this->arguments->getArgument('profile');

    $argument->getFileHandlingServiceConfiguration()->addFileUploadConfiguration(
        (new FileUploadConfiguration('image'))
            ->setMaxFiles(1)
            ->setUploadFolder('1:/user_upload/profiles/')
            ->setDuplicationBehavior(DuplicationBehavior::RENAME)
            ->addValidator($mimeTypeValidator)
            ->addValidator($fileSizeValidator)
    );

    $argument->getPropertyMappingConfiguration()->skipProperties('image');
}
```

Two traps come with the manual route, both of them silent:

| Trap                                                                                                     | Why it bites                                                                                                                                                                                         |
|----------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `FileUploadConfiguration`'s `duplicationBehavior` default is `RENAME` (`FileUploadConfiguration.php:40`) | The attribute's default is `REPLACE` (`cms-extbase/Classes/Attribute/FileUpload.php:37`). Every example written against the attribute therefore behaves differently here. Set it explicitly, always. |
| `skipProperties()` must be called by hand                                                                | On the attribute path `FileHandlingService` does it for you (`:144`). Without it the property mapper also tries to map the uploaded file onto the property.                                          |

The upload path is `POST` only (`FileHandlingService.php:77-79`), which matches
the transport decision anyway. → [AJAX transport](ajax-transport.md)

## Replacement: random names and in-place replacement exclude each other

This is a genuine conflict in the API, not a configuration mistake, and it has
to be stated before the decision makes sense.

- `addRandomSuffix` (default `true`) appends a 16-character hex string to the
  **client-supplied basename**: `holiday.jpg` becomes
  `holiday-3f2a…c1.jpg` (`FileHandlingService.php:387-405`, v13.4 `:385-402`).
  It is a suffix, not a replacement — the name the user's file had survives into
  the public URL.
- `DuplicationBehavior::REPLACE` only fires when the target filename **already
  exists** in the target folder (`cms-core/Classes/Resource/ResourceStorage.php:2061-2066`,
  v13.4 `:2281-2286`). Otherwise `addFile()` runs and a new `sys_file` is
  created.

A random suffix guarantees the name does not exist, so `REPLACE` can never
trigger. The two coherent configurations are:

| Configuration                             | Result                                                                                           |
|-------------------------------------------|--------------------------------------------------------------------------------------------------|
| `addRandomSuffix = true`                  | Unguessable public URL, a new `sys_file` per upload, the previous one orphaned.                  |
| `addRandomSuffix = false` + a stable name | Same `sys_file` uid and same public URL forever, no orphans — but a predictable, enumerable URL. |

**Decision: random names plus explicit cleanup.** The deterministic route is
harder than it looks and buys less than it looks: a stable name has to be
derived from the entity, and the only supplied hook,
`ModifyUploadedFileTargetFilenameEvent`
(`cms-extbase/Classes/Event/Service/ModifyUploadedFileTargetFilenameEvent.php`,
identical on both versions), carries **only the filename and the
`FileUploadConfiguration`** — not the domain object, not the current property
value, not the request. A listener cannot work out "the name this profile's
image already has". Threading that state in from the controller means feeding an
`@internal` service, and an unguessable URL for a user-uploaded portrait is
worth keeping regardless.

### What the API leaves behind

On a re-upload the API does this:

1. A new `sys_file` record and a new physical file are created.
2. The **existing** `sys_file_reference` is reused — only its `uid_local` is
   repointed, through `setOriginalResource()` on the already-loaded reference
   (`FileHandlingService.php:290-292`,
   `cms-extbase/Classes/Domain/Model/FileReference.php:37-41`). That makes the
   entity dirty and produces an `UPDATE sys_file_reference SET uid_local = …`
   for the same row.
3. The previous `sys_file` record and its file on disk stay exactly where they
   are, referenced by nobody.

Nothing collects them. `ext:form` got a cleanup command for its upload folders
in v14.2 (Feature #89951); Extbase has no equivalent, on either version.

### The cleanup, and the safeguard that makes it safe

After a successful re-upload the previous `sys_file` and its physical file are
deleted — **but only when exactly one reference to that file remains**.

The safeguard is load-bearing, not defensive. Deleting a `sys_file` hard-deletes
**every** `sys_file_reference` row pointing at it, in every table, without
condition:

```php
// cms-core/Classes/Resource/Processing/FileDeletionAspect.php:79-85
// remove all references
$this->connectionPool->getConnectionForTable('sys_file_reference')->delete(
    'sys_file_reference',
    [
        'uid_local' => $fileObject->getUid(),
    ]
);
```

(v13.4: `:73-80`, same statement.) It runs on `AfterFileDeletedEvent`, so it is
not something a caller can opt out of. The failure mode of an unguarded delete
is therefore not "an orphaned file wastes disk" — it is *the image silently
disappearing from records we do not own*.

**Counting the references.** Count non-soft-deleted `sys_file_reference` rows
with `uid_local = <old sys_file uid>` through a `QueryBuilder` with a
`DeletedRestriction` (`sys_file_reference` is soft-delete capable,
`cms-core/Configuration/TCA/sys_file_reference.php:11`). Consult `sys_refindex`
in addition, on `ref_table = 'sys_file'` and `ref_uid = <uid>`
(`cms-core/ext_tables.sql:230-235`), because it also records non-FAL usages such
as a `t3://file` link in RTE content, which no `sys_file_reference` row covers.
Extbase does maintain the reference index for the rows it writes
(`cms-extbase/Classes/Persistence/Generic/Backend.php:603`, `:744`, `:811`), so
the index is not stale *because of us* — it can still be stale for other
reasons, which is why the rule is asymmetric:

> Delete only when the reference count is exactly one **and** `sys_refindex`
> shows nothing else. In every other case keep the file. An orphan costs disk;
> a wrongly deleted file costs somebody else's record.

**Where the check belongs.** In a stateless service called by the persistence
service — not in the controller and not in a PSR-14 listener — because both of
its constraints are persistence ordering constraints:

1. **Capture the previous `sys_file` uid before persisting.** By the time the
   action body runs, the in-memory reference already points at the new file:
   mapping happens in `callActionMethod()` at `ActionController.php:466-467`,
   before the action is called. The old uid therefore comes from the entity's
   clean state — `$reference->_getCleanProperty('uidLocal')`
   (`cms-extbase/Classes/DomainObject/AbstractDomainObject.php:239`) — which
   still holds it, because `setOriginalResource()` only overwrote the live
   property.
2. **Run the deletion after `persistAll()`.** Before it, the database row still
   carries the old `uid_local`, so the count would include our own row *and* the
   `FileDeletionAspect` above would delete the reference row we just repointed.

Two notes that belong with this:

- **The built-in `@delete` flow is not used.** It deletes the `sys_file` and the
  physical file unconditionally (`FileHandlingService.php:344-345`), and the
  13.3 feature changelog says so in as many words: "Files are deleted directly
  without checking whether the current file is referenced by other objects."
  Clearing the image goes through the same guarded path — null the property,
  persist, then clean up.
- **"Physical file deleted" depends on the storage.**
  `ResourceStorage::deleteFile()` moves the file into the nearest `_recycler_`
  folder when the storage has one, instead of unlinking it (`:1794-1804`). That
  is a useful safety net, and it means disk usage does not necessarily drop.

## The nullable single reference

TCA is written in core's own modern spelling for a single image — `relationship`
rather than `maxitems => 1`, as `be_users.avatar` does
(`cms-core/Configuration/TCA/be_users.php:116-118`):

```php
'image' => [
    'config' => [
        'type' => 'file',
        'relationship' => 'manyToOne',
        'allowed' => 'common-image-types',
    ],
],
```

No `foreign_*` key is hand-written: `TcaPreparation` expands `type = file` into
the full inline relation, including `foreign_match_fields`
(`cms-core/Classes/Configuration/Tca/TcaPreparation.php:192-205`), and
`relationship` is evaluated ahead of `foreign_field` in
`RelationshipType::fromTcaConfiguration()`
(`cms-core/Classes/Schema/RelationshipType.php:47-55`). Both classes are
identical between v13.4 and v14.3 apart from a removed `@internal` docblock.

For the Extbase side the **model property type wins over the TCA**: a
non-collection property typed with a class name becomes a `Relation::HAS_ONE`
with `parentKeyFieldName` taken from `foreign_field`
(`cms-extbase/Classes/Persistence/Generic/Mapper/ColumnMapFactory.php:131-141`;
v13.4 reaches the same mapping through `setOneToOneRelation()`, before v14
rewrote `ColumnMap` to readonly named-argument construction). Because
`parentKeyFieldName` is set, reads resolve through the foreign key and the value
in the parent column is not read at all.

Null persists cleanly — a nullable domain-object property whose value is `null`
is written as `0` (`Backend.php:921-924`). Note that "nullable" here is a **PHP
model** concept only: `FileFieldType::isNullable()` returns `false`
unconditionally (`cms-core/Classes/Schema/Field/FileFieldType.php:66-69`) and
the column stays `int NOT NULL`.

### The divergence worth watching

| Writer      | Value stored in the parent's `image` column                    |
|-------------|----------------------------------------------------------------|
| Extbase     | the child `sys_file_reference` **uid** (`Backend.php:290-296`) |
| DataHandler | the **number** of children                                     |

A record created in the frontend and later edited in the backend therefore ends
up with a different number in that column than it started with. Both readers
resolve the relation by foreign key, so both paths keep working — the stored
value is simply not stable across the two writers.

**This is flagged as a POC risk, not as a settled fact.** It is verified against
FormEngine in the implementation: create a profile with an image in the
frontend, open and save it in the backend, re-open it in the frontend, and
assert the image survives all four steps. If any FormEngine or DataHandler path
turns out to read that column as a count, the finding becomes a real bug, and
the fix is to write the count ourselves after `persistAll()`.

## Validators

The upload validators are **identical across the two versions**: same
`$supportedOptions`, same error codes, all `final`; the files differ only in the
visibility of three helper methods (`protected` on v13.4, `private` on v14.3).
Nothing here needs a version split.

Two are enforced whether they are configured or not
(`cms-extbase/Classes/Mvc/Controller/FileHandlingServiceConfiguration.php:254-257`):

| Validator                                   | Purpose                                                     |
|---------------------------------------------|-------------------------------------------------------------|
| `FileNameValidator`                         | Rejects filenames that are not allowed by the installation. |
| `FileExtensionMimeTypeConsistencyValidator` | Rejects a file whose extension and MIME type disagree.      |

The ones we configure ourselves:

| Validator                  | Notes                                                                                           |
|----------------------------|-------------------------------------------------------------------------------------------------|
| `MimeTypeValidator`        | `allowedMimeTypes` is required — an empty array throws 1708526223.                              |
| `FileExtensionValidator`   | `allowedFileExtensions`, or `useStorageDefaults`.                                               |
| `FileSizeValidator`        | Sizes are strings — a number followed by `B`, `K`, `M` or `G`. Anything else throws 1708595605. |
| `ImageDimensionsValidator` | Must only run on a file already known to be an image, so it is ordered after the MIME check.    |

Custom validators extend
`\TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator` and **never
implement `ValidatorInterface` directly**: v14 added `setRequest()` and
`getRequest()` to the interface (Breaking #106056), and only the abstract class
covers both versions. Custom validators are also the second reason the manual
configuration route is unavoidable — the attribute cannot declare one.

## Behaviour that shapes the UI

- **Nothing is moved into storage on a validation failure.** Validation runs
  before mapping (`ActionController.php:461-467`); on errors the error method
  runs instead and `mapUploadedFilesToArgument()` is never reached. Nothing is
  cached or parked temporarily, so the file has to be sent again. In a classic
  form that means the user re-picks it; our JavaScript component still holds the
  `File` object and can re-send it without a re-pick — which is a UI decision,
  and the reason the error response has to identify the image field precisely
  enough for the component to know that this is what happened.
  → [DTOs and validation](dto-and-validation.md)
- **An unchanged image survives a save that does not touch it**, even with the
  upload marked required: when the property already holds a `FileReference` and
  no file was uploaded, per-file validation returns early
  (`FileHandlingServiceConfiguration.php:200-205`). This is what makes partial
  saves of other fields work at all.
- **`f:form` does not set `enctype`.** `UploadViewHelper`'s own docblock says so
  (`cms-fluid/Classes/ViewHelpers/Form/UploadViewHelper.php:21`). Our component
  posts a `FormData` body through `fetch`, which sets the multipart content type
  itself, but any Fluid fallback form must carry
  `enctype="multipart/form-data"` explicitly.

## Named gaps

Deliberately out of scope for the proof of concept, and listed so they are
decisions rather than omissions:

- **One image only.** `ObjectStorage<FileReference>` is supported by the API,
  but multiple images bring sorting and per-item deletion with them, and neither
  adds anything to the questions this POC answers.
- **No metadata editing.** Alternative text, title and crop variants live on
  `sys_file_metadata` and `sys_file_reference`, and are not exposed by the edit
  plugin.
- **No translated file references.** Consistent with the rest of the POC, which
  creates records with `sys_language_uid = 0` only.

## See also

- [Version neutral Extbase attributes](../architecture/version-neutral-attributes.md)
- [DTOs and validation](dto-and-validation.md)
- [Persistence and sorting](persistence-and-sorting.md)
- [Domain and schema](domain-schema.md)
- [Plugins and the Fluid layer](plugins-and-fluid.md)
- [AJAX transport](ajax-transport.md)
- [Class design](../architecture/class-design.md)
- [Modern frontend editing](Index.md)
