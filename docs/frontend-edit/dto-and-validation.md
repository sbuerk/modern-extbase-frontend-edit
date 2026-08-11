# DTOs and validation

The frontend edit endpoint takes a JSON body, not form-encoded controller
arguments, and answers with a machine readable error list rather than a
re-rendered form. That removes the two mechanisms Extbase normally provides —
argument mapping and `#[Validate]` — and replaces them with a data transfer
object, a rule set expressed as data, and one stateless validation service.

This page records the decisions. The implementation follows in a later change.

## Validation rules are data, never attributes

`#[Validate]` cannot be written once for both supported core versions. The
attribute exists in a different namespace and with a different constructor:

- v13 has `TYPO3\CMS\Extbase\Annotation\Validate` with
  `__construct(array $values)` and no `Attribute\` namespace at all.
- v14 has `TYPO3\CMS\Extbase\Attribute\Validate` with
  `__construct(string|array $validator, array $options = [], ?string $param = null)`.

Both spellings that follow from this are unusable here:

| Spelling                                   | v13         | v14                 |
|--------------------------------------------|-------------|---------------------|
| `#[Validate(validator: 'NotEmpty')]`       | `TypeError` | works               |
| `#[Validate(['validator' => 'NotEmpty'])]` | works       | `E_USER_DEPRECATED` |

The named-argument form is a `TypeError` on v13, because v13's constructor takes
a single array. The array form still works on v14 through the class alias and
the compatibility branch, but v14 answers it with

```php
// Classes/Attribute/Validate.php:46-51
if (is_array($validator)) {
    trigger_error(
        'Passing an array of configuration values to Extbase attributes will be removed in TYPO3 v15.0. '
        . 'Use explicit constructor parameters instead.',
        E_USER_DEPRECATED,
    );
```

and this repository's suites fail on a deprecation. A `use` statement does not
help either — the FQCN itself differs, so the import would have to differ per
version, which is exactly what shared code below `Classes/` must not do.

The same problem exists for `#[FileUpload]` and `#[Cascade]`. It is a pattern,
not an accident, and it is documented separately in
[Version neutral Extbase attributes](../architecture/version-neutral-attributes.md).

The way out here is not a `Core13/`/`Core14/` split of the DTO — a data object
is the worst possible thing to duplicate, because its FQCN is referenced from
the controller, the templates and the tests. Instead, the rules move out of the
class metadata and become ordinary PHP data.

## The rule set

Each DTO gets a sibling rule-set object that returns one array:

```
array<string, list<array{0: class-string<ValidatorInterface>, 1: array<string, mixed>}>>
```

Keyed by property name, each entry a validator FQCN plus its option array. That
option array is exactly what `AbstractValidator::setOptions()` consumes, which
is why the whole catalogue of core validators is reusable unchanged and nothing
in the rule set is version specific — the validator classes, their
`$supportedOptions` and their error codes are identical in v13.4 and v14.3.

```php
#[Exclude]
final readonly class ProfileEditDataRules implements ValidationRuleSetInterface
{
    private const LL = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:';

    public function rules(): array
    {
        return [
            'title' => [
                [NotEmptyValidator::class, [
                    'emptyMessage' => self::LL . 'validation.title.empty',
                ]],
                [StringLengthValidator::class, [
                    'minimum' => 3,
                    'maximum' => 255,
                    'lessMessage' => self::LL . 'validation.title.tooShort',
                    'exceedMessage' => self::LL . 'validation.title.tooLong',
                ]],
            ],
            'email' => [
                [EmailAddressValidator::class, [
                    'message' => self::LL . 'validation.email.invalid',
                ]],
            ],
        ];
    }
}
```

Two details that are easy to get wrong:

- **FQCNs, not shorthand identifiers.** `'NotEmpty'` resolves on both versions,
  but the namespaced shorthand `Vendor.Ext:MyValidator` was removed in v14. A
  class constant is statically analysable, survives a rename and cannot drift.
- **`setOptions()` is always called, even with an empty array.** It is what
  merges the declared defaults into `$this->options`; skipping it leaves the
  array empty and e.g. `StringLengthValidator::isValid()` reads
  `$this->options['maximum']` unguarded.

## Full and partial validation

The endpoint has two shapes of request: a complete form submit, and an inline
save of a single field. They use the same rule set through two entry points of
one stateless service.

|               | Full                                                     | Partial                                                    |
|---------------|----------------------------------------------------------|------------------------------------------------------------|
| Input         | a hydrated DTO                                           | one property name plus the raw submitted value             |
| Mechanism     | a throwaway `GenericObjectValidator` carrying every rule | only the leaf validators registered for that one property  |
| Result        | `$objectValidator->validate($dto)`                       | merged into `$result->forProperty($name)`                  |
| Absent fields | not applicable, the payload is complete                  | cannot produce an error — their validators are never built |

`GenericObjectValidator` is public, non-`@internal` API, identical in both
versions, and `addPropertyValidator()` / `getPropertyValidators()` are the only
hooks needed. It reads property values through `ObjectAccess`, so the DTO's
public promoted properties are readable without a getter.

The partial path deliberately does **not** validate a DTO and then filter the
result. Filtering is a decision taken after the errors already exist, and it has
two failure modes that the structural version does not have:

1. Building a `final readonly` DTO from a single submitted field forces made-up
   defaults for every other property — values that were never sent, are then
   validated, and are silently treated as valid input.
2. `NotEmptyValidator` is the only leaf validator with
   `$acceptsEmptyValues = false`; every other one short-circuits on `null` and
   `''`. A filter would therefore have to special-case exactly that validator to
   stop it firing on unsubmitted fields, and would go wrong again the moment a
   custom validator makes the same choice.

Not instantiating a validator is a guarantee. Discarding its output afterwards
is a convention that the next contributor can break without noticing.

## `ValidatorResolver` is not used

`ValidatorResolver::getBaseValidatorConjunction()` looks like the obvious way to
validate an arbitrary object, and it is a trap for a long-running AJAX endpoint:

- It is `@internal` in **both** versions, on the class, not just on individual
  methods.
- It caches the built conjunction per target class on a `SingletonInterface`
  service, and `GenericObjectValidator` is stateful.
- `AbstractGenericObjectValidator::validate()` returns `$this->result` unchanged
  when the instance is already in `$validatedInstancesContainer`, and that
  container is never reset. Validating, mutating and re-validating the same DTO
  in one request therefore returns the **stale** `Result` from the first call.
- It recurses into every non-simple property type and throws outright
  (`1363778104`) when any property lacks a type declaration.

Constructing a fresh `GenericObjectValidator` per call avoids all four, costs
nothing, and keeps our service genuinely stateless.
`ValidatorResolver::createValidator()` is skipped for the same reason plus one
more: it calls `setRequest()`, which does not exist on the v13
`ValidatorInterface`.

Validators themselves may be obtained with `GeneralUtility::makeInstance()`.
Extbase tags every `ValidatorInterface` implementation and runs
`PublicServicePass('extbase.validator', true)` over it in both versions, which
makes them public **and non-shared** — every fetch is a fresh instance, so their
statefulness cannot bleed between calls.

## What this forces on the DTO

1. `final readonly`, promoted **public** properties, no setters. The constructor
   parameter list is the whitelist; there is nothing to mass-assign.
2. Every property carries a PHP type declaration.
3. `#[Exclude]`, per the repository rule on data objects — and here for a second,
   sharper reason, see below.
4. A hand-written `fromArray()` named constructor that reads only known keys,
   with an explicit `$data['title'] ?? ''` per property.
5. **Never `uid`, `pid` or `sys_language_uid`.** Those are resolved server side
   from the request context and the record being edited. `setPid()` is public on
   every Extbase model and `determineStoragePageIdForNewRecord()` prefers the
   object's own pid over all configuration, so a `pid` that arrives from a client
   is a storage-location injection.

### The `#[Exclude]` trap, concretely

`ObjectConverter::buildObject()` starts with:

```php
// Classes/Property/TypeConverter/ObjectConverter.php:240-243
if ($this->container->has($objectType)) {
    return $this->container->get($objectType);
}
```

A DTO below a directory loaded by `Configuration/Services.php` and missing
`#[Exclude]` is known to the container. Anything mapping into it therefore gets
a container-built instance and **every submitted value is silently dropped** —
no exception, no message, an empty DTO that validates against whatever its
defaults happen to be. This is the concrete failure mode behind the general rule
in [Class design](../architecture/class-design.md#exclude-is-mandatory-on-all-of-them).

## Hydration is hand written

`PropertyMapper` is **not** deprecated in v14 — worth stating, because the
opposite is easy to assume given how much of the surrounding Extbase surface was
deprecated in that release. No changelog in `14.0`–`14.3` mentions it, and the
class is unchanged in substance. It is avoided on merit, not on status:

1. **Its failure mode is not a `Result`.** `convert()` returns `null` on a
   mapping error and hides the reason in `getMessages()`, or throws a
   `Property\Exception` wrapping the real cause. An endpoint whose whole job is
   to answer "which field is wrong" needs the errors as data.
2. **It is a `SingletonInterface` with mutable message state.** Using it means
   resetting messages around every call or accepting bleed between calls —
   exactly the state this repository forbids in services.
3. **Nothing in a JSON payload needs a type converter.** JSON already yields
   strings, ints, floats, bools and arrays. The one non-trivial case is dates,
   and an explicit
   `\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, …)` with a
   `false` return turned into a validation error is both shorter and more
   precise than `DateTimeConverter`'s configuration surface.
4. **Partial saves never build a DTO at all**, so half the mapping layer would
   be unused anyway.

If `PropertyMapper` is ever needed for a type converter, it is configured with an
explicit `allowProperties(...)` plus `skipUnknownProperties()` — never
`allowAllProperties()` — and `CONFIGURATION_OVERRIDE_TARGET_TYPE_ALLOWED` stays
at its default `false`, otherwise a `__type` key in the payload selects the
target class.

## The rule set is the single whitelist

The rule set answers both questions the endpoint has to ask, and it answers them
from one place:

- **What is validated** — the properties it declares rules for.
- **Which field names a partial save may address** — a property name arriving
  from the client is looked up in the rule set first, and an unknown name is
  rejected before it is used to select validators or, later, reach a column.

Keeping those two lists separate would let them drift, and the drift would be
invisible until a field silently became writable without rules.

## Custom validators are the exception to the data-object rule

A custom validator is not a data object and must not be treated as one:

- **No `#[Exclude]`.** Extbase autoconfigures every `ValidatorInterface`
  implementation into the container, public and non-shared. `#[Exclude]` removes
  it, `makeInstance()` falls back to plain `new`, and constructor injection
  breaks. Core's own `CollectionValidator` demonstrates that constructor
  dependencies are legitimate here.
- **Not `readonly`.** `AbstractValidator` writes `$this->result` and
  `$this->options` on every `validate()` call. `final` is fine and expected.
- **Always extend `AbstractValidator`, never implement `ValidatorInterface`
  directly.** v14 added `setRequest()` and `getRequest()` to the interface
  (Breaking #106056); a direct implementation would need both, which v13 does not
  know. The abstract class covers both versions.

## Messages are `LLL:EXT:` keys

Every configured message is a fully qualified
`LLL:EXT:modern_extbase_frontend_edit/…:key`. That is the one form whose
semantics are identical on both versions:
`AbstractValidator::translateErrorMessage()` translates anything starting with
`LLL:` and returns everything else verbatim, so no `$extensionName` argument is
needed — and `$extensionName` is precisely the argument whose behaviour changed,
since v13 throws for a non-`LLL:` key without it while v14 returns `null` and
additionally accepts translation domains.

No hardcoded English strings, and no `LocalizationUtility::translate()` call of
our own: the validators do it. Which option keys count as messages is declared
per validator in `$translationOptions` — `message` by default, but
`nullMessage`/`emptyMessage` for `NotEmptyValidator` and
`betweenMessage`/`lessMessage`/`exceedMessage` for `StringLengthValidator`.

The response ships `message`, `code` and `arguments` per error, keyed by the
dotted property path from `Result::getFlattenedErrors()`. Arguments are
`sprintf`-positional, so the frontend can either use the rendered string or
format it itself.

## See also

- [Version neutral Extbase attributes](../architecture/version-neutral-attributes.md)
- [Class design](../architecture/class-design.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [Strictness policy](../testing/phpunit-configuration.md#strictness-policy)
