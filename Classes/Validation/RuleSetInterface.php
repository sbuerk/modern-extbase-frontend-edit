<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation;

use TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface;

/**
 * The validation rules of one data transfer object, expressed as data.
 *
 * ## Why rules are data and not attributes
 *
 * `#[Validate]` has no spelling that is valid on TYPO3 v13 *and* free of
 * deprecations on v14: v13 ships `Extbase\Annotation\Validate` with
 * `__construct(array $values)`, v14 ships `Extbase\Attribute\Validate` with
 * explicit parameters and answers the array form with `E_USER_DEPRECATED`. This
 * repository fails a test run on any deprecation, so neither form passes both
 * matrices — see `docs/architecture/version-neutral-attributes.md`.
 *
 * The array returned by {@see rules()} is exactly what
 * `AbstractValidator::setOptions()` consumes, so the whole catalogue of core
 * validators is reused unchanged and nothing here is version specific: the
 * validator classes, their `$supportedOptions` and their error codes are
 * identical in v13.4 and v14.3.
 *
 * ## A rule set is the single whitelist
 *
 * It answers both questions the edit endpoint has to ask, from one place:
 *
 * - **What is validated** — the properties it declares rules for.
 * - **Which field names a partial save may address** — a property name arriving
 *   from a client is looked up here first, and an unknown name is rejected
 *   before it selects validators or, later, reaches a column. That rejection is
 *   {@see DtoValidator::validateProperty()} throwing
 *   {@see Exception\UnknownPropertyException}, never a silent no-op.
 *
 * Keeping the two lists separate would let them drift, and the drift would stay
 * invisible until a field silently became writable without rules.
 *
 * A property may therefore be listed with an **empty** rule list: that declares
 * "writable, no constraints" and is a deliberate statement, not an oversight.
 *
 * ## Two constraints on every entry
 *
 * - **Fully qualified validator class names, never shorthand identifiers.**
 *   `'NotEmpty'` still resolves on both versions, but the namespaced shorthand
 *   `Vendor.Ext:MyValidator` was removed in v14. A `::class` constant is
 *   statically analysable, survives a rename and cannot drift.
 * - **Every validator must tolerate a `mixed` value.** Partial validation runs
 *   the leaf validators against the raw submitted value, which is whatever the
 *   JSON payload contained. A validator that assumes a string — core's
 *   `RegularExpressionValidator` calls `preg_match()` on it under
 *   `declare(strict_types=1)` — turns a malformed payload into a `TypeError`
 *   instead of a validation error, and must not appear in a rule set.
 *
 * Implementations are data, not services: `#[Exclude]`, `final readonly`, and
 * created with `new` by whoever needs them.
 */
interface RuleSetInterface
{
    /**
     * The validators of each writable property, keyed by property name.
     *
     * Every entry is a tuple of a validator class name and the option array
     * handed to `AbstractValidator::setOptions()`. Message options are
     * fully qualified `LLL:EXT:…` keys, which is the one form whose semantics
     * are identical on both core versions:
     * `AbstractValidator::translateErrorMessage()` translates anything starting
     * with `LLL:` and returns everything else verbatim, so no `$extensionName`
     * argument is needed — and `$extensionName` is precisely the argument whose
     * behaviour changed between v13 and v14.
     *
     * Which option keys count as messages is declared per validator in its
     * `$translationOptions`: `message` by default, `nullMessage`/`emptyMessage`
     * for `NotEmptyValidator`, `betweenMessage`/`lessMessage`/`exceedMessage`
     * for `StringLengthValidator`.
     *
     * @return array<non-empty-string, list<array{0: class-string<ValidatorInterface>, 1: array<string, mixed>}>>
     */
    public function rules(): array;
}
