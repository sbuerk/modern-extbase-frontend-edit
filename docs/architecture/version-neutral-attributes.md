# Version neutral Extbase attributes

Three Extbase attributes have **no spelling that is valid and deprecation-free
on both TYPO3 v13 and v14**: `#[Validate]`, `#[FileUpload]` and
`#[Cascade]`. Each of them moved namespace in v14 *and* changed its constructor
from a single configuration array to explicit parameters, and the array form now
triggers `E_USER_DEPRECATED`. Since this repository fails a test run on any
deprecation, neither the old nor the new form passes both matrices.

This page exists because the failure is easy to walk into and, in one of the
three cases, completely silent. The implementations described here follow in
later changes.

## The shape of the problem

Two changes landed together in v14 and interact badly for code that has to run
on v13 as well:

| Change                                                               | Effect                                                         |
|----------------------------------------------------------------------|----------------------------------------------------------------|
| Deprecation #107229 — `Extbase\Annotation\*` → `Extbase\Attribute\*` | The FQCN changed. The old name survives only as a class alias. |
| Feature #97559 / Deprecation #97559 — properties instead of arrays   | The constructor changed. The array form now deprecates.        |

The class alias makes the *namespace* shareable — `Annotation\Cascade` still
resolves on v14. The constructor change is what breaks: v13's constructor is
`__construct(array $values)`, so anything written with named arguments is a
`TypeError` there, and anything written as an array raises a deprecation on v14.
There is no third form.

`use` statements cannot bridge this. The import would have to differ per core
version, and shared code below `Classes/` does not carry version conditionals —
see [Core version aware code](core-version-aware-code.md).

## `#[Validate]`

|                | v13                                     | v14                                                                                 |
|----------------|-----------------------------------------|-------------------------------------------------------------------------------------|
| FQCN           | `TYPO3\CMS\Extbase\Annotation\Validate` | `TYPO3\CMS\Extbase\Attribute\Validate` (old name aliased)                           |
| Constructor    | `__construct(array $values)`            | `__construct(string\|array $validator, array $options = [], ?string $param = null)` |
| Named argument | `TypeError`                             | works                                                                               |
| Array form     | works                                   | `E_USER_DEPRECATED` (`Classes/Attribute/Validate.php:46-51`)                        |

Both directions fail loudly, which at least means a wrong choice is caught by the
first test run on the other core version.

**Escape route: rules as data.** Validation rules move out of the class metadata
into a rule-set object that returns `property => list<[validator FQCN, options]>`
— the exact input `AbstractValidator::setOptions()` expects, so every core
validator is reused unchanged and nothing in the rule set is version specific.
Details and the resulting DTO contract are in
[DTOs and validation](../frontend-edit/dto-and-validation.md).

## `#[FileUpload]`

|                 | v13                                       | v14                                                                                                                                                                                      |
|-----------------|-------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| FQCN            | `TYPO3\CMS\Extbase\Annotation\FileUpload` | `TYPO3\CMS\Extbase\Attribute\FileUpload` (old name aliased)                                                                                                                              |
| Constructor     | `__construct(array $values)`              | `__construct(array $validation, string $uploadFolder = '', bool $addRandomSuffix = true, bool $createUploadFolderIfNotExist = true, DuplicationBehavior $duplicationBehavior = REPLACE)` |
| Named argument  | `Unknown named parameter $validation`     | recommended (#97559)                                                                                                                                                                     |
| Array form      | works                                     | `E_USER_DEPRECATED` (`Classes/Attribute/FileUpload.php:40-45`)                                                                                                                           |
| v14 FQCN on v13 | **silently does nothing**                 | —                                                                                                                                                                                        |

The last row is the dangerous one. v13's `ClassSchema` matches only
`Annotation\FileUpload`; the v14 FQCN falls into the `default` arm of the
`match`, `Property::getFileUpload()` stays `null`, and `FileHandlingService`
skips the property. `ReflectionProperty::getAttributes()` still reports the
attribute, no exception is raised, no deprecation is logged — **the form simply
never uploads anything on v13**. Nothing in a green test suite catches that
unless the suite actually asserts a persisted file reference.

**Escape route: `FileUploadConfiguration` in `initialize<Action>()`.** The
programmatic API — `Argument::getFileHandlingServiceConfiguration()` plus
`FileUploadConfiguration`'s fluent setters — is **byte identical** between v13.4
and v14.3, is the only way to register custom upload validators at all, and keeps
the model free of version specific metadata. Two things the attribute did for
free and this route does not:

- `duplicationBehavior` defaults to `RENAME` on the configuration object and to
  `REPLACE` on the attribute. Set it explicitly.
- `getPropertyMappingConfiguration()->skipProperties(...)` must be called by
  hand; `FileHandlingService` only does that for attribute-driven properties.

## `#[Cascade]`

|                                     | v13                                                  | v14                                                          |
|-------------------------------------|------------------------------------------------------|--------------------------------------------------------------|
| FQCN                                | `TYPO3\CMS\Extbase\Annotation\ORM\Cascade`           | `TYPO3\CMS\Extbase\Attribute\ORM\Cascade` (old name aliased) |
| Constructor                         | `__construct(array $values)` — the array is required | `__construct(string\|array\|null $value = null)`             |
| `#[Cascade('remove')]`              | fatal `TypeError`                                    | works                                                        |
| `#[Cascade(['value' => 'remove'])]` | works                                                | `E_USER_DEPRECATED`                                          |

**Escape route: do not use it.** Orphans are removed explicitly — the edit
service tracks every child taken out of an `ObjectStorage` and calls
`$repository->remove($child)` before `persistAll()`.

This is not merely the cheaper of two options; `#[Cascade]` would not solve the
problem even if it were writable once. It is honoured for a `HAS_MANY` property
on detach, but a replaced or cleared `HAS_ONE` — our file reference — is never
cascaded, because `persistObject()` only writes `0` into the parent column. And
on a `#[Lazy]` `HAS_ONE` property the cascade is skipped **silently**:
`LazyLoadingProxy` does not implement `DomainObjectInterface`, so the
`elseif` in `removeRelatedObjects()` is simply false. Explicit removal has to
exist regardless, at which point the attribute only adds a second, partly
overlapping mechanism.

## What *is* safely shareable

Attributes without constructor arguments are unaffected by Feature #97559, and
the class alias covers the namespace move without a runtime notice. `#[Lazy]`
and `#[Transient]` can therefore be written once, from the `Annotation\ORM`
namespace, and work identically on both versions.

That is the general rule: **the argument list is the risk, not the namespace.**
An Extbase attribute that takes no arguments is fine today. One that takes them
has to be checked.

## The rule this implies

An Extbase attribute is used in shared code below `Classes/` only after its
constructor has been read in **both** core versions — the installed one and the
other. "It works" from a single-version test run is not evidence; the
`#[FileUpload]` case proves that the failure can be a no-op rather than an error.

When an attribute turns out to be unwritable once, the answer is a **data-driven
or explicit-API form**, not a `Core13/`/`Core14/` split of the model. Splitting a
data object is the worst available option: its FQCN is referenced from TCA,
`Configuration/Extbase/Persistence/Classes.php`, the controller, the templates
and the tests, so a split multiplies configuration rather than isolating a
difference. Version splits belong to services and adapters, where the container
picks the implementation and nothing else has to know.

## See also

- [DTOs and validation](../frontend-edit/dto-and-validation.md)
- [Core version aware code](core-version-aware-code.md)
- [Class design](class-design.md)
- [Strictness policy](../testing/phpunit-configuration.md#strictness-policy)
