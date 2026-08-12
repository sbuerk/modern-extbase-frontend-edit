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

> [!NOTE]
> **This is code now, both halves of it.** The read side is `Profile::$image`,
> `Profile::getProfileImage()`, the scalar-only value object
> `Domain\Model\ProfileImage` and `Partials/Profile/Image.html`. The write side
> is [the two endpoints](#the-two-endpoints) of
> `Classes/Controller/ProfileAjaxController.php`,
> the upload configuration in its `initializeUploadImageAction()`, the two image
> methods of `Domain\Persistence\ProfilePersistenceService`, the guard in
> `Domain\Persistence\UnreferencedFileCleanupService` and the accepted formats in
> `Validation\ProfileImageUploadRules`. In the browser it is
> `component/editImage.ts` and `model/imageEdit.ts`.
>
> Writing and running it disproved four statements this page made while it was
> design only. All four are corrected **in place**, next to the reasoning they
> replace, rather than collected in an errata list nobody reads — a reader
> arriving at a section is the reader who needs its correction:
>
> - the reference count that may delete a replaced file
>   → [the cleanup](#correction-one-remaining-reference-was-the-wrong-threshold);
> - that `skipProperties()` is the only property mapping call the manual upload
>   route needs
>   → [the third trap](#allowproperties-is-the-third-trap-and-the-quietest);
> - that `setMaxFiles(1)` is how "exactly one image" is expressed
>   → [the fourth](#setmaxfiles1-makes-replacement-impossible);
> - and that a core validator message can be replaced with one of ours
>   → [the validators](#one-core-message-cannot-be-overridden-on-v143).

## The two endpoints

The image is written by two actions of `ProfileAjaxController`, and they are the
eighth and ninth of [the endpoint set](ajax-transport.md#the-nine-endpoints).
Everything the other seven do — the action in the query string and therefore in
the cHash, the `X-TYPO3-RequestToken` header, the session-resolved record, the
workspace refusal, the uniform `404` — holds here unchanged. One thing does not:
the request body of the upload.

| Action        | Request                                                         | What it does                                                                    |
|---------------|-----------------------------------------------------------------|---------------------------------------------------------------------------------|
| `uploadImage` | `POST`, `multipart/form-data`, one file part and one `uid` part | Stores the picked file as the profile's image, replacing the previous one.      |
| `removeImage` | `POST`, `application/json`, `{"uid": 42}`                       | Clears the image. Idempotent — removing an absent image is `200`, not an error. |

**The upload is multipart because a JSON body cannot carry a file without
base64**, and base64 is the wrong trade twice over: it inflates the payload by a
third, and it holds the whole file in memory as a string in addition to the
bytes it encodes — on both ends. A `FormData` streams the file as it is
(`Build/Sources/TypeScript/api/payload.ts:147-171`).

The useful side effect is that the endpoint can use Extbase's own machinery at
all. `ServerRequestFactory` fills the parsed body from `$_POST`, which PHP
populates for a multipart or form encoded body and for nothing else, so
`uploadImage` is the only action of that controller with an Extbase argument and
the only one whose payload it does not decode by hand.

`removeImage` stays JSON precisely because nothing is uploaded: it carries a uid
and nothing else, so the multipart exception has nothing to buy there and the
media type check of the JSON endpoints — a second, independent CSRF barrier a
cross origin `<form>` cannot pass — keeps applying to it.

### The parts, and why they are spelled that way

| Part                                                | Value                                 |
|-----------------------------------------------------|---------------------------------------|
| `tx_modernextbasefrontendedit_ajax[profile][image]` | The file. One part, never several.    |
| `tx_modernextbasefrontendedit_ajax[uid]`            | The profile uid, as a decimal string. |

Neither name is free. `Argument::getUploadedFilesForProperty()` looks the
property up *inside* the uploaded files of the argument, and the request builder
namespaces those by plugin, so the path is
`<plugin>[<argument>][<property>]` — the argument is
`ProfileAjaxController::UPLOAD_ARGUMENT` and the property is
`ProfileImageUploadRules::PROPERTY`, both `public` constants for exactly that
reason.

A multipart body carries no types: every field arrives as a string. The uid is
therefore the one value in that controller accepted in a decimal spelling — and
in that spelling only, matched against `/^[1-9][0-9]*$/` rather than cast, because
it selects a record and two accepted spellings are how a check and a lookup end
up disagreeing about what was addressed
(`Classes/Controller/ProfileAjaxController.php:888-900`). It is still a
**filter** on the set the session owns, exactly like the `uid` of every JSON
payload.

### The answers

| Status | When                                                                                         |
|--------|----------------------------------------------------------------------------------------------|
| `200`  | The file is stored. Body is the whole aggregate, as every other endpoint answers.            |
| `400`  | Not multipart, no `uid`, a `uid` that is not a positive integer, or more than one file part. |
| `403`  | Missing or invalid request token, or no frontend user.                                       |
| `404`  | The `uid` is not in the owned set — the same body a uid that does not exist produces.        |
| `405`  | Anything but `POST`, with an `Allow` header.                                                 |
| `409`  | A workspace is active.                                                                       |
| `422`  | The upload was refused by a validator. Messages are keyed by the field name `image`.         |

The `422` is `errorAction()`, which is replaced rather than extended: the
inherited one forwards to the referring request or renders **HTML** with status
`400`, and a client that always parses JSON can read neither
(`ProfileAjaxController.php:703-711`). The results are merged at the root of a
fresh `Result` so that the flattened key is `image` — the model property name,
which is what `FileHandlingServiceConfiguration` keyed the errors under and what
the surface knows the control by.

**Both endpoints answer the same `JsonEnvelope` document as every other
endpoint**, and that is a rule rather than a convenience. The component applies
every answer through one path — replace the state wholesale, or place the
messages at their fields — so a second envelope shape would be a second way for
the surface to disagree with the server about what was persisted.
→ [The server is the source of truth](edit-plugin.md#the-server-is-the-source-of-truth)

### The client must not set `Content-Type` for the multipart body

The header of a multipart request is not `multipart/form-data`, it is
`multipart/form-data; boundary=…`, and the boundary is generated by whoever
serialises the body — for a `FormData` handed to `fetch`, the browser. Setting
the header by hand keeps our value and drops the boundary with it, PHP then has
no way to split the parts, and `$_POST` and `$_FILES` arrive empty: a request
with no file and no arguments, from code that looks entirely correct in the
editor and in the network panel.

`ProfileEndpointClient` therefore sets `Content-Type` for the JSON bodies only
and lets `fetch` fill it in for a `FormData`
(`Build/Sources/TypeScript/api/client.ts:103-113`). It is the one place where
the two body kinds differ; everything else about the request — the verb, the
credentials, the token header, the interpretation of the answer — is shared.

### One file part, checked explicitly

`assertSingleUploadedImage()` refuses a body carrying more than one part for the
image property (`ProfileAjaxController.php:859-872`). Without it a client
sending several parts would have every one of them validated and only the first
one stored, which is a request that half succeeded and reported nothing.

Sending **no** file is deliberately not refused there. That is what `minFiles`
is for — `setRequired()` sets it to `1` — and it produces a `422` keyed by
`image`, an answer the surface can show next to the control that produced it
rather than a bare `400`.

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

This is the shape as it is written, minus the transport and ownership checks
that come first — `Classes/Controller/ProfileAjaxController.php:560-613` is the
whole method:

```php
protected function initializeUploadImageAction(): void
{
    // Transport, token, session and workspace are asserted first, and the
    // resolved record is written into the request before mapping runs.

    $configuration = new FileUploadConfiguration(ProfileImageUploadRules::PROPERTY);
    $configuration->setUploadFolder($this->imageUploadFolder());
    $configuration->setRequired();
    // No setMaxFiles() — see below.
    $configuration->setDuplicationBehavior(DuplicationBehavior::RENAME);
    $configuration->setAddRandomSuffix(true);
    foreach ((new ProfileImageUploadRules())->validators() as $validator) {
        $configuration->addValidator($validator);
    }
    $configuration->ensureValidConfiguration(FileReference::class);

    $argument = $this->arguments->getArgument(self::UPLOAD_ARGUMENT);
    $argument->getFileHandlingServiceConfiguration()->addFileUploadConfiguration($configuration);
    $argument->getPropertyMappingConfiguration()
        ->allowProperties(ProfileImageUploadRules::PROPERTY)
        ->skipProperties(ProfileImageUploadRules::PROPERTY);
}
```

`ensureValidConfiguration()` is called here rather than left to
`mapUploadedFilesToArgumentForProperty()`, so that a mistyped upload folder
fails at the line that configured it instead of deep inside `callActionMethod()`.
The setters are fluent except `setDuplicationBehavior()`, which returns `void`
(`FileUploadConfiguration.php:168`) — a chain written through it does not
compile, which is why the real code uses statements.

Four traps come with the manual route, and every one of them is silent. The
first two were predicted; the last two were found by running the endpoint:

| Trap                                                                                                     | Why it bites                                                                                                                                                                                         |
|----------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `FileUploadConfiguration`'s `duplicationBehavior` default is `RENAME` (`FileUploadConfiguration.php:40`) | The attribute's default is `REPLACE` (`cms-extbase/Classes/Attribute/FileUpload.php:37`). Every example written against the attribute therefore behaves differently here. Set it explicitly, always. |
| `skipProperties()` must be called by hand                                                                | On the attribute path `FileHandlingService` does it for you (`:144`). Without it the property mapper also tries to map the uploaded file onto the property.                                          |
| `allowProperties()` must be called as well                                                               | It is not the opposite of `skipProperties()` — it is what decides whether the upload runs at all. Omitted, the endpoint answers `200` and stores nothing.                                            |
| `setMaxFiles(1)` makes replacement impossible                                                            | It counts the upload *plus what the property already holds*, so a profile that has an image can never receive another one.                                                                           |

The upload path is `POST` only (`FileHandlingService.php:77-79`), which matches
the transport decision anyway. → [AJAX transport](ajax-transport.md)

### `allowProperties()` is the third trap, and the quietest

> [!IMPORTANT]
> **Correction.** An earlier revision of this page listed `skipProperties()` as
> the one property mapping call the manual route needs. It is not.
> `allowProperties()` is required too, and leaving it out fails as silently as
> everything else on this path: the response is an ordinary `200`, nothing was
> stored, and no error was reported anywhere.

The two calls look like opposites and are not. They fill **two independent
arrays** on `PropertyMappingConfiguration`, read by two different methods, and
only one of them decides whether the *upload* happens:

| Call                | Fills                   | Read by        | Consulted by                                                          |
|---------------------|-------------------------|----------------|-----------------------------------------------------------------------|
| `allowProperties()` | `$propertiesToBeMapped` | `shouldMap()`  | `FileHandlingService::shouldMapProperty()` — whether the upload runs. |
| `skipProperties()`  | `$propertiesToSkip`     | `shouldSkip()` | The property *mapper* — whether it maps the request value as well.    |

```php
// cms-extbase/Classes/Service/FileHandlingService.php:375-382
private function shouldMapProperty(Argument $argument, string $propertyName): bool
{
    if ($propertyName === '') {
        return false;
    }

    return $argument->getPropertyMappingConfiguration()->shouldMap($propertyName);
}
```

`shouldMap()` answers `true` for a property in `$propertiesToBeMapped`, and
otherwise falls back to `$mapUnknownProperties`
(`cms-extbase/Classes/Property/PropertyMappingConfiguration.php:98-113`), which
defaults to `false` (`:84`). `allowProperties()` is the only thing that fills
that array (`:145-151`); `skipProperties()` fills the other one (`:162-168`).

The reason this never shows up in an example is that a Fluid form fills the
allow-list for you: `MvcPropertyMappingConfigurationService` derives it from the
HMAC signed `__trustedProperties` field the form rendered. **A hand-built
request carries no such field**, and every request this controller answers is
hand-built — so without the explicit call the allow-list is empty, `shouldMap()`
answers `false`, `mapUploadedFilesToArgument()` skips the property, and the
uploaded file is discarded without a word.

Allowing the property is not a hole here, and the reason is worth stating
because "allow" reads like one: the property mapper never runs for this argument
at all. Its value is already a `Profile` instance by the time mapping starts —
`initializeUploadImageAction()` wrote the session-resolved record into the
request, and `setArgumentValue()` short circuits for a value that already is an
instance of the argument type.

### `setMaxFiles(1)` makes replacement impossible

> [!IMPORTANT]
> **Correction.** `setMaxFiles(1)` is the obvious way to say "one image" and it
> is wrong. It is deliberately **not** called; `assertSingleUploadedImage()` in
> the controller enforces "one file part" instead.

`maxFiles` does not count the upload. It counts the upload **plus what the
property already holds**, minus the deletions registered in the same request:

```php
// cms-extbase/Classes/Mvc/Controller/FileHandlingServiceConfiguration.php:221
if ((count($uploadedFiles) + $currentAmount - $fileDeletionCount) > $configuration->getMaxFiles()) {
```

`$currentAmount` is `1` for a property already holding a `FileReference`
(`:173-179`). With `maxFiles = 1` a profile that has an image can therefore never
receive a new one — `1 + 1 - 0 > 1` — and **every replacement answers `422`**
while a first upload succeeds, which is the kind of bug that passes a smoke test
and fails in use.

Leaving it unset is safe: the default is `PHP_INT_MAX`
(`FileUploadConfiguration.php:37`), so the check can only ever fire for a
configured bound. `setRequired()` is called instead, and it sets `minFiles = 1`
(`:101-105`) — a bound that means what it says, because `minFiles` counts the
uploaded files and nothing else.

The only way to satisfy it would be to register a deletion in the same request,
which means the built-in `@delete` flow — and that flow deletes a `sys_file`
without checking who else references it, which is precisely what
[the cleanup guard](#the-cleanup-and-the-safeguard-that-makes-it-safe) exists to
prevent. Leaving `maxFiles` unset and checking the part count ourselves costs
one private method and keeps the guard.

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
deleted — **but only when nothing except our own reference row points at it**.
That rule is `Classes/Domain/Persistence/UnreferencedFileCleanupService.php`,
and it replaces a threshold this page had wrong; the correction is
[below](#correction-one-remaining-reference-was-the-wrong-threshold), after the
mechanism that makes the threshold matter.

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

> Delete only when **both** counts are zero once our own reference row is
> excluded from them. In every other case keep the file. An orphan costs disk;
> a wrongly deleted file costs somebody else's record.

### Correction: "one remaining reference" was the wrong threshold

> [!CAUTION]
> **Correction, and this one was dangerous.** An earlier revision of this page
> wrote the rule as *"delete only when the reference count is exactly one"*.
> Following it would have deleted files that were still in use, and with them
> the references of records this extension does not own.

The mistake is an ordering mistake. The cleanup runs **after `persistAll()`**,
and by then our own `sys_file_reference` row has already been repointed at the
*new* file — that is the whole reason the deletion is safe to attempt at all. So
the old file has **zero** references from us, not one. A count of exactly one
therefore does not mean "only our row is left"; it means **somebody else's row
is left**, which is precisely the case that must keep the file.

Under the old wording the two outcomes were exactly inverted:

| Rows pointing at the old file after the flush | Old rule ("exactly one") | Implemented rule ("none but ours") |
|-----------------------------------------------|--------------------------|------------------------------------|
| Nothing — the ordinary replacement            | keep, an orphan forever  | **delete**                         |
| One row of another record                     | **delete**               | keep                               |

And "delete" in the second row is not a wasted file. Deleting the `sys_file`
fires the `FileDeletionAspect` above, which removes **every**
`sys_file_reference` pointing at it — so the other record loses its image, with
no error anywhere and nothing in its own history to explain it.

The implemented rule is stated in terms of the caller instead of in terms of a
number: *no references other than the caller's own row, which is excluded by
uid*. `deleteWhenUnreferenced($fileUid, $ignoredFileReferenceUid)` takes that
uid, excludes it from the `sys_file_reference` count with a `neq` on `uid`
(`UnreferencedFileCleanupService.php:182-189`) and from the `sys_refindex` count
by `(tablename, recuid)` (`:239-252`), and then refuses the deletion unless
**both** counts are zero (`:118-123`).

Naming the row rather than counting to one is also what makes the threshold the
same in every path. On a replacement our row survives and is excluded; on a
removal it was soft deleted first and is excluded again — including from
`sys_refindex`, whose entry survives that soft delete because FAL's
`FileReference::delete()` writes the `deleted` flag straight through DBAL and
updates no index. One threshold, zero, everywhere.

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
3. **Re-resolve the reference from the persisted row afterwards.** The core
   `FileReference` the upload service built is synthetic — it was constructed
   with `'uid' => 'NEW_…'`, so `getUid()` answers `0` and a response document
   built from it would report a reference uid the client cannot use.
   `ProfilePersistenceService::saveProfileImage()` fetches the stored reference
   once the row exists and sets it back, which writes the same `uidLocal` and
   therefore leaves nothing dirty behind.

Three notes that belong with this:

- **The built-in `@delete` flow is not used.** It deletes the `sys_file` and the
  physical file unconditionally (`FileHandlingService.php:344-345`), and the
  13.3 feature changelog says so in as many words: "Files are deleted directly
  without checking whether the current file is referenced by other objects."
  Clearing the image goes through the same guarded path — null the property,
  persist, soft delete the reference row, then clean up. That is
  `ProfilePersistenceService::removeProfileImage()`, and it is idempotent: a
  profile without an image is answered by doing nothing.
- **"The physical file is deleted" is storage dependent.**
  `ResourceStorage::deleteFile()` moves the file into the nearest `_recycler_`
  folder when the storage has one, instead of unlinking it
  (`cms-core/Classes/Resource/ResourceStorage.php:1794-1804`). The `sys_file`
  record is gone either way, and nothing in the frontend can tell the two apart —
  but disk usage does not necessarily drop, and on a storage with a recycler the
  bytes of every replaced portrait are still there. An installation that
  expects a deletion to *free* space has to say so through its storage
  configuration; this extension cannot promise it and does not.
- **A deletion that is refused is not an error.** The cleanup answers `false`
  for a `sys_file` that has already vanished and for the three refusals
  `ResourceStorage::assureFileDeletePermissions()` raises, and the caller does
  not react: the write it performed has already succeeded and been persisted, and
  a surviving file is the safe outcome of that method rather than a failed one.

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

### What an upload has to satisfy

The three we configure are `Validation\ProfileImageUploadRules`, which is data
rather than a service: a `final readonly` `#[Exclude]` class listing validator
class names and their options, handed to `FileUploadConfiguration::addValidator()`
as fresh instances. Fresh per call, never cached — a validator accumulates its
`Result` in a property, which is why Extbase tags validators as non-shared
services in the first place.

| Rule       | Value                                          | Enforced by                                          |
|------------|------------------------------------------------|------------------------------------------------------|
| Formats    | JPEG, PNG, GIF, WebP                           | `MimeTypeValidator`, `allowedMimeTypes`              |
| Size       | at most 5 MB                                   | `FileSizeValidator`, `maximum = '5M'`                |
| Dimensions | at most 5000 px on either edge, no lower bound | `ImageDimensionsValidator`, `maxWidth` / `maxHeight` |

Four things about that list are decisions:

- **SVG is excluded deliberately.** It is an XML document a browser executes,
  `text/html` smuggled through an `image/svg+xml` MIME type is stored XSS
  whenever the file is served from the same origin, and nothing here needs vector
  portraits. Adding it back is a security decision, not a convenience one.
- **The size limit is not the only one.** `upload_max_filesize` and
  `post_max_size` cut in before PHP ever builds `$_FILES`, and a request refused
  there never reaches the endpoint with a usable body. Keep the configured value
  at or below them, so that the answer a user gets is ours and not the web
  server's.
- **The dimension bound is a resource limit, not a taste one.** Image processing
  allocates roughly four bytes per pixel, so an unbounded edge is the cheapest
  way to make thumbnail generation expensive. There is deliberately **no** lower
  bound: what makes an acceptable portrait is a decision for the site that adopts
  this template, and `minWidth`/`minHeight` stay at their `0` default.
- **The order is documentation, not control flow.** All configured validators
  are run over the file and the run does not stop at the first failure
  (`FileHandlingServiceConfiguration.php:237-244`), so a file can be refused for
  two reasons at once. `ImageDimensionsValidator` is still listed after the MIME
  check, because it is meaningful only for a file that is an image and says so
  itself by returning without an error when width or height cannot be read.

The option spellings have sharp edges of their own: `allowedMimeTypes` must be a
non-empty array or the validator throws 1708526223; sizes are **strings** — a
number followed by `B`, `K`, `M` or `G` — and anything else throws 1708595605
for `minimum` and 1708595606 for `maximum`.

None of our messages carries a placeholder for a value the request supplied.
`MimeTypeValidator` passes the detected MIME type and the submitted extension as
message arguments, and a message without a conversion specification simply drops
them — which is what keeps the response from echoing back what it just refused.
The size and dimension messages do carry `%1$s`, and that argument is the bound
*we* configured: telling a user the limit they exceeded is the entire point.

Every message is a fully qualified `LLL:EXT:…` key, which is the one form whose
semantics are identical on both core versions: `translateErrorMessage()`
translates anything starting with `LLL:` and returns everything else verbatim,
so no `$extensionName` argument is needed — and that argument is precisely what
changed between v13 and v14.

### How the three rules are covered

Each rule is exercised through the endpoint, by a file that breaks that rule and
nothing else, and the assertion is the **error code** rather than the status: all
three rules and the two core adds answer `422` under the same field name, so
"something refused this" is what a test of a single rule must not settle for.

| Test                                                             | Payload                                                    | Expected code                                           |
|------------------------------------------------------------------|------------------------------------------------------------|---------------------------------------------------------|
| `aRejectedUploadIsRefusedAndStoresNothing`                       | three files with a wrong type or extension                 | field name only                                         |
| `anImageLargerThanTheConfiguredMaximumIsRefusedAndStoresNothing` | the fixture PNG padded to `MAXIMUM_FILE_SIZE` + 1 byte     | `1708595755`, `FileSizeValidator`                       |
| `anImagePastADimensionBoundIsRefusedAndStoresNothing`            | `profile-image-too-wide.png`, `profile-image-too-tall.png` | `1715964044` / `1715964045`, `ImageDimensionsValidator` |

The two dimension fixtures are 5001 px on one edge and a single pixel on the
other, which keeps them a few hundred bytes and keeps each of them breaking
exactly one bound — a 5001 by 5001 image would break both at once and could not
tell a missing `maxHeight` from a missing `maxWidth`.

Two properties of that arrangement are worth stating rather than discovering:

- **The size test follows the bound; it does not pin it.** Its payload is
  computed from `MAXIMUM_FILE_SIZE`, so raising the constant raises the payload
  and the test stays green. It proves the rule is applied, not that the value is
  5 MB. The dimension tests do pin their bound, because their fixtures cannot
  follow it — raise `MAXIMUM_EDGE` and they fail until new fixtures are made.
- **The size rule is what keeps a large upload an answer instead of a crash.**
  Delete it and the padded file is not refused, reaches
  `ResourceStorage::assureFileUploadPermissions()` and throws `UploadSizeException`
  1322110041 — the test environment's `upload_max_filesize` is 2 MB, below our
  5 MB bound. That is a statement about the *test* environment: through a browser
  PHP refuses the body before `$_FILES` exists, and `getMaxUploadFileSize()`
  reads nothing but those two ini settings
  (`cms-core/Classes/Utility/GeneralUtility.php:2004-2016`), so the branch is
  unreachable from a real request. It is still the sharpest available
  demonstration that our bound is evaluated first.

What a request cannot show is covered next to it. `Tests/Unit/Validation/ProfileImageUploadRulesTest`
pins that `validators()` hands out **fresh** instances — a cached one would
answer the second upload of a session with the errors of the first, and every
functional test of a single request would still pass — and that every option name
survives `setOptions()`, which throws 1379981890 for a key the installed core
version does not declare. `Tests/Functional/Validation/ValidationMessageTest`
collects the upload rules alongside the rule sets, so all five message keys are
proven to resolve and every rule is proven to name a message of its own.

### One core message cannot be overridden on v14.3

> [!IMPORTANT]
> **Correction, and it is a core defect rather than a design decision of ours.**
> An earlier revision of this page assumed every validator message could be
> replaced with one of ours. `FileExtensionMimeTypeConsistencyValidator`'s cannot
> be, on v14.3, and its message **echoes the detected MIME type and the
> submitted file extension back to the client**.

The validator declares its translatable options and its actual option under two
different names:

```php
// cms-extbase/Classes/Validation/Validator/FileExtensionMimeTypeConsistencyValidator.php:31-39
protected string $notAllowedMessage = 'LLL:EXT:extbase/…:validation.fileextensionmimetypeconsistency.notallowed';
protected array $translationOptions = ['inconsistentMessage'];

protected $supportedOptions = [
    'notAllowedMessage' => [null, 'Translation key or message for inconsistent mime-type for file extension', 'string'],
];
```

`AbstractValidator::initializeTranslationOptions()` walks `$translationOptions`
and assigns only the ones that name an existing property:

```php
// cms-extbase/Classes/Validation/Validator/AbstractValidator.php:223-230
foreach ($this->translationOptions as $translationOption) {
    if (property_exists($this, $translationOption)) {
        $this->$translationOption = $options[$translationOption] ?? $this->$translationOption;
    }
}
```

There is no `inconsistentMessage` property, so nothing is assigned; and
`notAllowedMessage`, which *is* the property the validator reads at `:83-87`, is
not in the list. Passing `notAllowedMessage` as an option is accepted — it is a
supported option — and then silently ignored. Core's own message reaches the
client, and it reads *The resolved media type "%s" is not allowed for file
extension "%s"*, with both values filled in from the request.

That is a defect worth reporting upstream rather than working around: the fix is
one word in core, and every workaround available here is worse. Replacing the
validator with an identically named subclass of our own would opt out of the
enforced default (`enforceDefaultValidators()` compares class names) and would
put a security relevant consistency check into this extension's hands for the
sake of a message. Our own messages avoid echoing request data, for the reason
`Http\JsonEnvelope` states; this one is not ours to shape.

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
  cached or parked temporarily, anywhere — not in the storage, not in a
  temporary folder — so **the file has to be chosen again**, and the surface has
  to say so rather than leave the impression that it still holds it. That is the
  `error.imageNotStored` label, appended below the server's own messages: those
  say *why* it was refused, and this one says what state the control is in. The
  file input is cleared the moment the file is read, for the same reason and for
  a second one — a `change` event only fires when the value changes, so a user
  re-picking the *same* file after fixing it on disk would otherwise select
  nothing and the surface would sit there silently.
  → [DTOs and validation](dto-and-validation.md)
- **An unchanged image survives a save that does not touch it**, even with the
  upload marked required: when the property already holds a `FileReference` and
  no file was uploaded, per-file validation returns early
  (`FileHandlingServiceConfiguration.php:201-206`). This is what makes partial
  saves of other fields work at all — the `save` and `saveField` payloads carry
  no image, and cannot.
- **`f:form` does not set `enctype`.** `UploadViewHelper`'s own docblock says so
  (`cms-fluid/Classes/ViewHelpers/Form/UploadViewHelper.php:21`). Our component
  posts a `FormData` body through `fetch`, which sets the multipart content type
  itself, but any Fluid fallback form must carry
  `enctype="multipart/form-data"` explicitly.
- **There is no draft, no apply and no cancel for the image.** A text field has
  three states and `Apply`/`Cancel` exist for the middle one. A file has two:
  there is nothing to look at between picking a file and having uploaded it,
  because the thing the user wants to see is the *stored* image and only the
  server can produce it. Picking a file therefore **is** the write, and Escape is
  not bound, because it cancels a draft and there is none.

### The image is rendered inside the custom element

On the detail view the image partial sits wherever the template puts it. On the
edit plugin it is rendered **inside** `<modern-extbase-frontend-edit-profile>`,
next to the name heading, and that is a consequence of the two endpoints
existing rather than a layout preference.

Everything inside the element is the no-JavaScript view, and it is replaced the
moment the element upgrades. Everything outside it survives, unchanged, for the
lifetime of the page. While no endpoint managed the image, outside was the
natural place for it: nothing could make the served markup disagree with the
server. Now something can. After a replacement the surface shows the
new file immediately — it re-renders from the document the endpoint answered
with — while a copy left outside would still show the file the page was loaded
with, side by side, and **the page would disagree with itself about which image
the profile has**.

The name heading was inside from the start for exactly the same reason, and the
image has now joined it. It is also why the edit template does not use
`Profile/Card`: the card bundles the image with the name and the links, and the
links are not part of an editing surface.
→ [The enhanced surface is client-rendered](edit-plugin.md#the-enhanced-surface-is-client-rendered)

## A successful upload can only be simulated on v14

The feature behaves identically on both core versions. The **test** of a
successful upload does not: on TYPO3 v13 a functional test cannot produce a
request that `ResourceStorage` accepts at all.

Every write that moves an uploaded file into a storage passes
`ResourceStorage::assureFileUploadPermissions()`.

- **v13** resolves the `UploadedFile` to its temporary path first
  (`cms-core/Classes/Resource/ResourceStorage.php:2274`) and then calls
  `is_uploaded_file()` on it unconditionally (`:1095`), throwing
  `UploadException` 1322110455 on `:1096`. PHP answers that check `false` for
  every file the SAPI did not receive as an HTTP upload in the same process, so
  a `UploadedFile` a test constructed is refused before this extension is
  reached.
- **v14** takes the uploaded file itself — `string|array|UploadedFileInterface`
  — and performs the check only on the string branch, "(no additional
  `is_uploaded_file` check on purpose)": v14.3.5 `ResourceStorage.php:1004-1018`.
  The change is forge #107027, *[TASK] Replace $_FILES with PSR-7 UploadedFile in
  ExtendedFileUtility*, released for `main` only; its commit message states the
  intent as "this allows functional testing via `UploadedFile`".

A browser upload arrives through the SAPI on both versions, so nothing about the
endpoint, the validators or the cleanup differs at runtime. What differs is what
a test can construct.

The consequence for this repository is deliberately narrow:

| Coverage                                                                             | v13                                                  | v14     |
|--------------------------------------------------------------------------------------|------------------------------------------------------|---------|
| Refusals — authorization, ownership, request token, transport, validation, workspace | yes                                                  | yes     |
| A successful upload, replacement and removal, asserted on the raw rows               | no, excluded by group                                | yes     |
| A successful upload through a real browser and apache                                | yes, `Tests/Acceptance/Frontend/ImageUpload.spec.ts` | not run |

The excluded tests carry
`AbstractProfileAjaxTestCase::UPLOAD_CANNOT_BE_SIMULATED_ON_CORE_13`, whose value
is the `not-core-13` group `runTests.sh` excludes for a v13 run. The refusal
tests carry nothing and run everywhere, which is where the security relevant
assertions of this feature live.
→ [Functional tests](../testing/functional-tests.md#a-test-can-be-grouped-because-the-core-differs) ·
[Acceptance tests](../testing/acceptance-tests.md)

## Named gaps

Deliberately out of scope for the proof of concept, and listed so they are
decisions rather than omissions:

- **One image only.** `ObjectStorage<FileReference>` is supported by the API,
  but multiple images bring sorting and per-item deletion with them, and neither
  adds anything to the questions this POC answers.
- **No metadata editing.** Alternative text, title and crop variants live on
  `sys_file_metadata` and `sys_file_reference`, and are not exposed by the edit
  plugin. The alternative text a visitor sees is therefore either the one stored
  on the reference by an editor, or the translated `profile.image.alt` sentence
  with the profile name substituted into it.
- **No cropping, no processing, no responsive variants.** The file is stored and
  served as it was uploaded; `Profile/Image.html` renders an `<img>` and
  deliberately not an `f:image`, so an installation that wants processed images
  replaces that one partial and nothing else.
- **No translated file references.** Consistent with the rest of the POC, which
  creates records with `sys_language_uid = 0` only.
- **Physical deletion is storage dependent**, as
  [the cleanup notes](#the-cleanup-and-the-safeguard-that-makes-it-safe)
  explain: a storage with a `_recycler_` folder receives the file instead of
  unlinking it.

## See also

- [Version neutral Extbase attributes](../architecture/version-neutral-attributes.md)
- [The edit plugin](edit-plugin.md)
- [DTOs and validation](dto-and-validation.md)
- [Persistence and sorting](persistence-and-sorting.md)
- [Domain and schema](domain-schema.md)
- [Plugins and the Fluid layer](plugins-and-fluid.md)
- [AJAX transport](ajax-transport.md)
- [Class design](../architecture/class-design.md)
- [Modern frontend editing](Index.md)
