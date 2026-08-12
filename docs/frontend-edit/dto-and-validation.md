# DTOs and validation

The frontend edit endpoint takes a JSON body, not form-encoded controller
arguments, and answers with a machine readable error list rather than a
re-rendered form. That removes the two mechanisms Extbase normally provides —
argument mapping and `#[Validate]` — and replaces them with a data transfer
object, a rule set expressed as data, and one stateless validation service.

This page records the decisions **and the code that implements them**. Three
DTOs, three rule sets, two custom validators, one validation service and three
mappers exist; nothing on this page is a plan any more.

What the layer does not do is stated once, plainly, in
[What this layer deliberately does not do](#what-this-layer-deliberately-does-not-do)
at the end — it validates and maps, and that is the whole of it.

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
final readonly class ProfileRuleSet implements RuleSetInterface
{
    private const LL = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:';

    public function rules(): array
    {
        return [
            'shortname' => [
                [NotEmptyValidator::class, [
                    'nullMessage' => self::LL . 'validation.profile.shortname.empty',
                    'emptyMessage' => self::LL . 'validation.profile.shortname.empty',
                ]],
                [StringLengthValidator::class, [
                    'minimum' => 2,
                    'maximum' => 255,
                    'betweenMessage' => self::LL . 'validation.profile.shortname.length',
                ]],
            ],
            'firstname' => [
                [StringLengthValidator::class, [
                    'maximum' => 255,
                    'exceedMessage' => self::LL . 'validation.profile.firstname.tooLong',
                ]],
            ],
        ];
    }
}
```

Three details that are easy to get wrong:

- **FQCNs, not shorthand identifiers.** `'NotEmpty'` resolves on both versions,
  but the namespaced shorthand `Vendor.Ext:MyValidator` was removed in v14. A
  class constant is statically analysable, survives a rename and cannot drift.
- **`setOptions()` is always called, even with an empty array.** It is what
  merges the declared defaults into `$this->options`; skipping it leaves the
  array empty and e.g. `StringLengthValidator::isValid()` reads
  `$this->options['maximum']` unguarded.
- **Every validator in a rule set has to tolerate a `mixed` value.** Partial
  validation runs the leaf validators against the raw submitted value, so a
  validator that assumes a string turns a malformed payload into a `TypeError`
  rather than into an error message. That single constraint is what rules out
  two of core's validators — see
  [The two custom validators](#the-two-custom-validators-and-why-core-has-no-answer).

### Which `StringLengthValidator` message actually fires

`StringLengthValidator` does **not** select its message by the bound that was
violated. It selects by the bounds that are *configured*:

```php
// .Build/vendor/typo3/cms-extbase/Classes/Validation/Validator/StringLengthValidator.php:78-115
if ($this->options['minimum'] > 0 && $this->options['maximum'] < PHP_INT_MAX) {
    // betweenMessage, code 1428504122, arguments [minimum, maximum]
} elseif ($this->options['minimum'] > 0) {
    // lessMessage, code 1238108068, arguments [minimum]
} else {
    // exceedMessage, code 1238108069, arguments [maximum]
}
```

The consequence is worth stating explicitly, because the option names invite the
opposite assumption:

| Configured bounds     | Message emitted  | Error code   | `sprintf` arguments  | Dead options                                   |
|-----------------------|------------------|--------------|----------------------|------------------------------------------------|
| `minimum` + `maximum` | `betweenMessage` | `1428504122` | `minimum`, `maximum` | `lessMessage`, `exceedMessage`                 |
| `minimum` only        | `lessMessage`    | `1238108068` | `minimum`            | `betweenMessage`, `exceedMessage`              |
| `maximum` only        | `exceedMessage`  | `1238108069` | `maximum`            | `betweenMessage`, `lessMessage`                |
| neither               | `exceedMessage`  | `1238108069` | `maximum`            | never fires — nothing can violate the defaults |

A rule configuring both bounds and both a `lessMessage` and an `exceedMessage`
therefore emits **neither** of them: a value that is too short and a value that
is too long both produce the `betweenMessage`, which then still carries the
extbase default text because nothing overrode it. No exception is raised, no
option is reported as unsupported — `$supportedOptions` accepts all three keys —
and the mistake is invisible until someone reads the rendered message.

So: **one rule, exactly one message key, chosen by the bounds it declares.** The
placeholders differ with it, which is the second half of the same trap. A
`betweenMessage` receives `%1$s` = minimum and `%2$s` = maximum, an
`exceedMessage` receives `%1$s` = maximum, and reusing one translation for both
kinds of rule prints the wrong number rather than failing.

`NotEmptyValidator` is the mirror image and needs the opposite treatment: it has
two messages that *both* fire, `nullMessage` for `null` and `emptyMessage` for
`''`, and a rule that sets only one of them falls back to the extbase default
for the other input. The rule sets set both to the same key.

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
   through a helper that normalizes anything non-string — see
   [Hydration is hand written](#hydration-is-hand-written). A bare
   `$data['shortname'] ?? ''` is **not** enough and is not what the code does.
5. **Never `uid`, `pid` or `sys_language_uid`.** Those are resolved server side
   from the request context and the record being edited. `setPid()` is public on
   every Extbase model and `determineStoragePageIdForNewRecord()` prefers the
   object's own pid over all configuration, so a `pid` that arrives from a client
   is a storage-location injection.
6. **Every property is a raw, JSON-native `string`** — never a converted value.

### Why every property is a plain string

`ProfileData::$birthday` is a `string`, not a `\DateTimeImmutable`, and that is
forced by the two entry points sharing one rule set:

- Full validation validates the **DTO**.
- Partial validation validates the **single raw submitted value**, because it
  never builds a DTO at all.

Both look up the same entry of the same rule set, so the rule set is only sound
if both paths present the *same type* for a given property. A parsed
`\DateTimeImmutable $birthday` would break that immediately: the partial path
would hand a string to a rule written for an object, and the rule would have to
accept both — at which point it no longer validates anything.

Conversion is therefore the **mapper's** job, and the wire format is pinned by a
constant on the DTO so that the mapper and the rule set cannot disagree about
it:

```php
// Classes/Dto/ProfileData.php
public const BIRTHDAY_FORMAT = 'Y-m-d';
```

`ProfileRuleSet` configures `DateStringValidator` with
`ProfileData::BIRTHDAY_FORMAT`, and `ProfileDataMapper::requireDate()` parses
with the same constant. Neither restates the literal.

**The format is `Y-m-d`, not `\DateTimeInterface::ATOM`**, and that is a
deliberate narrowing rather than an oversight. The column is
`type => datetime` with `format => date` and `dbType => date`, so it stores a
day and no time of day. Accepting an ATOM value would take a payload a client
took care to send in full and silently throw half of it away on write. Refusing
the value says so instead. Both the validator and the mapper prefix the format
with `!`, which resets every field the format does not carry — without it a
date-only parse leaves the current time of day in the result, which is the
difference between "the day that was submitted" and "that day at whatever
o'clock the request happened to arrive".

An earlier draft of this page proposed an ATOM parse in the mapper. It is
recorded here as rejected so it does not get reintroduced as a convenience.

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
   strings, ints, floats, bools and arrays, and the DTOs keep exactly those. The
   one non-trivial case is dates, and it is not a hydration problem at all —
   the DTO keeps the raw string, the rule set rejects a string that is not a
   date, and `ProfileDataMapper::requireDate()` does the one
   `createFromFormat()` that exists. That is both shorter and more precise than
   `DateTimeConverter`'s configuration surface.
4. **Partial saves never build a DTO at all**, so half the mapping layer would
   be unused anyway.

If `PropertyMapper` is ever needed for a type converter, it is configured with an
explicit `allowProperties(...)` plus `skipUnknownProperties()` — never
`allowAllProperties()` — and `CONFIGURATION_OVERRIDE_TARGET_TYPE_ALLOWED` stays
at its default `false`, otherwise a `__type` key in the payload selects the
target class.

### `?? ''` is not enough, and why

The obvious idiom for reading a key out of a decoded payload is wrong for a
`string` property:

```php
// no — a TypeError waiting for the first non-string payload
shortname: $data['shortname'] ?? '',
```

`??` only defends against an **absent** key. JSON is not restricted to strings,
so `{"bio": 42}`, `{"bio": []}` and `{"bio": null}` all pass that expression
straight into a promoted `string $bio` and raise a `TypeError` — a 500 for a
payload whose correct answer is a validation error, and one that is trivially
reachable by anyone who can post to the endpoint. Casting instead of checking is
no better: `(string)[]` emits an *Array to string conversion* warning, and
[this repository's suites fail on warnings](../testing/phpunit-configuration.md#strictness-policy).

Every DTO therefore hydrates through one private helper:

```php
// Classes/Dto/ProfileData.php
private static function stringValue(array $data, string $key, string $absentValue = ''): string
{
    if (!array_key_exists($key, $data)) {
        return $absentValue;
    }

    return is_string($data[$key]) ? $data[$key] : '';
}
```

The two cases are kept apart on purpose:

| Payload                   | Result         | Meaning                                              |
|---------------------------|----------------|------------------------------------------------------|
| key absent                | `$absentValue` | the property's declared default — a legitimate state |
| `"bio": "text"`           | `"text"`       | the submitted value                                  |
| `"bio": 42`, `[]`, `null` | `""`           | wrong type, normalized so the rule set can reject it |

The `''` in the last row is **not a fallback, it is a rejection handed to the
rule set**. A required field then fails its `NotEmptyValidator`, a select fails
its `ChoiceValidator`, and the client gets the field-keyed error list it would
get for any other invalid input. The DTO never decides that something is
invalid; it only makes sure the rule set is the layer that does.

`$absentValue` exists because a default is not always `''`: `AddressData::$type`
and `EmailData::$type` default to `'others'`, mirroring the `DEFAULT 'others'`
of their columns.

> [!IMPORTANT]
> This has a real trade-off and it is not hidden: **a wrongly typed value is
> indistinguishable from a cleared one** for an optional field. `{"bio": 42}`
> reads as "clear the bio", not as "you sent the wrong type", because `bio` has
> no rule that `''` violates.
>
> That was accepted rather than solved. Reporting it would mean a type error
> channel next to the validation `Result` — a second error shape the client has
> to render — for a case a correct client cannot produce, since the type of a
> field is fixed by the form that submits it. A required field is unaffected:
> its `NotEmptyValidator` rejects the normalized `''` and the client is told
> which field is wrong. If a future field genuinely needs the distinction, the
> place to make it is a rule on that property, not a change to this helper.

## The rule set is the single whitelist

The rule set answers both questions the endpoint has to ask, and it answers them
from one place:

- **What is validated** — the properties it declares rules for.
- **Which field names a partial save may address** — a property name arriving
  from the client is looked up in the rule set first, and an unknown name is
  rejected before it is used to select validators or, later, reach a column.

Keeping those two lists separate would let them drift, and the drift would be
invisible until a field silently became writable without rules.

A property may be listed with an **empty** rule list. That declares "writable, no
constraints" and is a deliberate statement rather than an oversight — which is
only possible because the list of names is the same list as the rules.

### An unknown field name throws, it does not become a `Result` entry

`DtoValidator::validateProperty()` rejects a name the rule set does not declare
by throwing `UnknownPropertyException` (`1786492306`), not by returning a
`Result` carrying "unknown field".

That is a distinction about **who the error is for**. The `Result` is the
structure a client uses to decorate form fields: every entry in it is a message
that belongs next to an input the user can see and correct. A field name the
server does not know is none of those things — it is a protocol error, the
client and the server disagree about the shape of the API, and no user action
fixes it. Putting it in the `Result` would give the client an entry with no field
to attach it to and would let a renamed or removed field degrade into a save that
silently writes nothing, reported as a per-field message somewhere in a list.

Failing loudly also keeps the whitelist honest. A rejected name never reaches
validator selection and never reaches a column, and the rejection is impossible
to mistake for a validation outcome. The transport layer turns the exception into
whatever status code is right for a malformed request; that mapping is
[AJAX transport](ajax-transport.md)'s business, not this layer's.

The full-submit path has no equivalent, and deliberately so: `fromArray()` reads
only known keys, so a payload carrying `uid`, `pid` or an invented field simply
never reaches a property. There is nothing to reject, because there is nothing
the name could have selected.

## The two custom validators, and why core has no answer

Everything else in the rule sets is a core validator used unchanged. Two rules
could not be expressed with one, and in both cases the reason is the same
constraint: **partial validation runs a leaf validator against the raw submitted
value**, so every validator in a rule set has to survive a `mixed`.

### `ChoiceValidator` — one of a fixed set

`type` on an address is one of `home`, `work`, `others`; on an e-mail address it
is one of `private`, `business`, `others`. TYPO3 ships **no** "value is one of
this set" validator at all, and the near match does not survive the constraint:

```php
// .Build/vendor/typo3/cms-extbase/Classes/Validation/Validator/RegularExpressionValidator.php:3,45
declare(strict_types=1);
…
$result = preg_match($this->options['regularExpression'], $value);
```

`preg_match()` takes a `string`, the file declares strict types, and the value
comes straight from the payload — so a partial save of `{"type": 7}` raises a
`TypeError`, which is a 500 where the correct answer was a validation error.

A regex would also encode the accepted set **twice**, once as TCA select items
and once as an alternation, with nothing keeping the two in step.
`ChoiceValidator` takes the set as a `choices` array and compares with strict
equality, so `7` is simply not in the set and `'7'` is not either.

It sets `$acceptsEmptyValues = false`, which is what makes `null` and `''` reach
`isValid()` at all — `AbstractValidator::validate()` skips `isValid()` for empty
values otherwise. For a select that is the only correct behaviour: the empty
string is not one of the choices unless it is listed as one, and accepting it
would let a partial save write `''` into a column whose TCA pins a non-empty
default. It is therefore also the reason the "filter a full validation result"
approach stays rejected: `NotEmptyValidator` is no longer the only validator that
fires on an unsubmitted value.

### `DateStringValidator` — a date that is still a string

Core's `DateTimeValidator` is an `instanceof` check, not a parser:

```php
// .Build/vendor/typo3/cms-extbase/Classes/Validation/Validator/DateTimeValidator.php:37-40
$this->result->clear();
if ($value instanceof \DateTimeInterface) {
    return;
}
```

No value that can arrive in a JSON payload passes it, because JSON has no date
type. Holding a parsed `\DateTimeImmutable` on the DTO would not help either —
the partial path never builds a DTO, so the same rule would face a string there
and an object here.

A regex cannot take the job either, and not only because of the `TypeError`
above: a pattern can check the *shape* of a date and nothing more. `2026-02-30`
and `2026-13-01` both match `\d{4}-\d{2}-\d{2}` and neither is a date. Only a
parse can tell — and the parse has to be read carefully, because
`createFromFormat()` does **not** fail on an out-of-range date:

```
\DateTimeImmutable::createFromFormat('!Y-m-d', '2026-02-30')  // an object, not false
                                                              // → 2026-03-02
```

The overflow is reported as a *warning* and the date is rolled forward, so a
`false` check alone accepts 30 February and silently stores 2 March. The
validator therefore rejects on `getLastErrors()` reporting any warning or error,
not just on `false`.

The format is an option (`'format'`), and `ProfileRuleSet` passes
`ProfileData::BIRTHDAY_FORMAT` rather than a literal, so the validator and the
mapper cannot drift apart. `$acceptsEmptyValues` stays at its inherited `true`:
an optional date is expressed by the *absence* of a `NotEmptyValidator` in the
rule set, not by a second opinion inside the validator.

## Custom validators are the exception to the data-object rule

A custom validator is not a data object and must not be treated as one. This is
the one documented exception to
[rule 4 on data objects](../architecture/class-design.md#data-objects-are-not-services):
a validator **may** take constructor dependencies, so it is registered like a
service and carries neither `#[Exclude]` nor `readonly`. Concretely:

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

## Mapping a validated payload onto the model

Validation answers "is this acceptable". The mappers answer "where does it go",
and they are three stateless services — `ProfileDataMapper`, `AddressDataMapper`,
`EmailDataMapper` — that hold nothing derived from a request: the model, the
payload and the resolved children are arguments on every call.

### `pid` and `uid` are impossible by mechanism, not by check

This is the last layer that could let a payload-controlled `pid` through, so it
is **closed** rather than guarded. Four properties of the code, together:

1. There is exactly one path from a payload value to a model: `applyProperty()`.
   Its dispatch is a **closed `switch`** over the mapper's `WRITABLE_PROPERTIES`,
   and every other name — `pid`, `uid`, `feUser`, `hidden`, anything a client
   invents — falls into `default`, which throws.
2. `map()` does not read the DTO dynamically. Every value is fetched by an
   explicit `match` arm, so a property the DTO grows later is invisible to the
   mapper until someone writes the arm.
3. `ProfileDataMapper` never calls `setPid()`. The child mappers call it in
   exactly one place, in `mapCollection()`, with `$parent->getPid()` — a value
   read off the already resolved, owner-constrained parent record. No argument of
   that call comes from a DTO.
4. `uid` has no setter on `AbstractDomainObject` at all. The only way in is
   `_setProperty()`, and the mapper namespace contains **no such call**.

The difference between this and a guard matters. A guard is a line someone can
delete, or forget to extend when a property is added; a closed dispatch makes the
unsafe case fail to compile into existence — making a property writable *means*
writing a `case` with a setter call. And the stake is high:
`Backend::determineStoragePageIdForNewRecord()` prefers an object's own pid over
`newRecordStoragePid` and over `persistence.storagePid`, so a record whose pid a
request may set is written wherever the request said. No amount of configuration
protects it. → [Authorization](authorization.md)

Type assertions in the mapper are strict and never coerce. Validation has already
run when a mapper is reached, so a wrong type there is a programming error or a
payload shape the rules did not cover — and casting `['x']` or `42` into a string
would turn both into stored data.

### The dispatch table and the rule set are kept in step by a test

`WRITABLE_PROPERTIES` is unavoidably a second list next to the rule set. They are
tied together by a test asserting that **every rule set key appears in the
mapper's dispatch table**. That direction is the one worth asserting: a validated
property the mapper cannot write throws at runtime, whereas a mapper property
that carries no rules is not a hole — a partial save naming it is rejected by the
rule set before it reaches the mapper at all.

### Child collections return the intended set, and nothing else

`mapCollection()` returns an `ObjectStorage` of the addresses (or e-mail
addresses) the payload intends, in the intended order. **It does not assign it,
reorder anything or delete anything.**

`$submitted` is keyed by the identity the client claims for each entry, which is
the shape a JSON object of children decodes into:

| Key                               | Meaning                                                           |
|-----------------------------------|-------------------------------------------------------------------|
| a positive integer                | addresses an existing child, looked up in `$existing`             |
| anything else (`"new-1"`, `0`, …) | creates a new child; the key reaches nothing but this distinction |

A positive key that is **not** in `$existing` throws. `$existing` is the
owner-constrained set the caller resolved, so an unknown key is either another
user's record or one that does not exist — and per
[Authorization](authorization.md) both must produce the same response. It is
never silently skipped.

`$existing` is **passed in** rather than read off `$parent->getAddresses()`, and
that is required rather than preferred: Extbase loads a relation through query
settings its data mapper builds from scratch, so hidden children are absent from
the parent's collection *however the parent itself was fetched*. Resolving the
parent with relaxed enable-field handling does not carry over to its children.
The caller assembles the set from the edit repository and hands it in.

A child present in `$existing` but absent from `$submitted` is not in the
returned set and is not touched. Detecting and deleting it is the write path's
job — detaching alone leaves the row behind with a cleared parent pointer and
`sorting = 0`. → [Persistence and sorting](persistence-and-sorting.md)

### `hidden` and `image` are not payload properties

Neither appears in a DTO, a rule set or a dispatch table, and neither omission is
an oversight.

- **`hidden`** is a state transition, not a form field. Publishing and
  unpublishing is a dedicated action with its own ownership assertion, and
  folding it into "the values of this record" would make it settable by anything
  that can save a field.
- **`image`** is a separate mechanism. Writing it means resolving a
  `sys_file_reference` and deleting the replaced file, none of which is a value
  assignment, and the upload arrives as a file rather than as JSON.
  → [Image handling](image-handling.md)

`feUser` is absent for the same class of reason as `pid`: ownership is resolved
from the session, never submitted.

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

## What this layer deliberately does not do

Stated plainly, because a gap that is named is a decision and a gap that is
hidden is a defect. **This layer validates and maps. That is all it does.**

| Not done here  | What is missing                                                                       | Where it belongs                                      |
|----------------|---------------------------------------------------------------------------------------|-------------------------------------------------------|
| Persistence    | no `add()`, `update()`, `remove()` or `persistAll()` anywhere in the mappers          | the write path                                        |
| Orphan removal | a child dropped from a payload is left untouched, not deleted                         | [Persistence and sorting](persistence-and-sorting.md) |
| Sorting        | `mapCollection()` returns the intended order; nothing applies it to a live collection | [Persistence and sorting](persistence-and-sorting.md) |
| HTTP surface   | no endpoint, no route, no request parsing, no response shape                          | [AJAX transport](ajax-transport.md)                   |
| Authorization  | `$existing` arrives already owner-constrained; nothing here resolves ownership        | [Authorization](authorization.md)                     |
| File upload    | `image` is not a payload property at all                                              | [Image handling](image-handling.md)                   |

Nothing on that list is blocked by a decision on this page — they are the next
change. The separation is also what makes the mappers unit testable without a
database, which is the practical reason the split falls exactly here.

## See also

- [Version neutral Extbase attributes](../architecture/version-neutral-attributes.md)
- [Class design](../architecture/class-design.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [Authorization](authorization.md)
- [Persistence and sorting](persistence-and-sorting.md)
- [AJAX transport](ajax-transport.md)
- [Strictness policy](../testing/phpunit-configuration.md#strictness-policy)
