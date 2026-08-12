<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Dto;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The editable fields of a {@see Profile}, as they arrive in a JSON payload.
 *
 * ## The DTO is not the entity
 *
 * Mass assignment is prevented by the *shape* of this class, not by a runtime
 * check: the constructor parameter list is the whitelist, the properties are
 * `public readonly` and there are no setters, so there is nothing to assign to
 * that was not named here.
 *
 * That is why there is **no `uid` and no `pid`**, and why `pid` does not exist
 * as a property at all rather than merely being ignored. `setPid()` is public
 * on every Extbase model and `Backend::determineStoragePageIdForNewRecord()`
 * prefers the object's own pid over all configuration, so a `pid` arriving from
 * a client is a storage-location injection. `sys_language_uid` is absent for the
 * same reason: it is resolved server side from the request context and the
 * record being edited. The record identity of an edit is resolved from the
 * owned set — see `Security\ProfileOwnershipResolverInterface` — never from the
 * payload.
 *
 * ## `#[Exclude]` is not decoration here
 *
 * `Configuration/Services.php` loads the whole `Classes/` tree, so without
 * `#[Exclude]` this class is known to the container. `ObjectConverter`
 * then short-circuits on it:
 *
 * ```php
 * // Classes/Property/TypeConverter/ObjectConverter.php:241-243
 * if ($this->container->has($objectType)) {
 *     return $this->container->get($objectType);
 * }
 * ```
 *
 * Anything mapping into a container-known DTO therefore receives a
 * container-built instance and **every submitted value is silently dropped** —
 * no exception, no message, an empty object that validates against whatever its
 * defaults happen to be. Do not remove the attribute.
 *
 * ## Why every property is a plain string
 *
 * The properties hold the raw, JSON-native values, not converted ones. Full
 * validation validates this object, partial validation validates the single raw
 * value that was submitted, and both use the same rule set — which is only
 * sound if both see the *same* type for a given property. A parsed
 * `\DateTimeImmutable $birthday` would break that: the partial path never
 * builds a DTO, so it would validate a string against rules written for an
 * object.
 *
 * Conversion is therefore the mapper's job, and the wire format is pinned by
 * {@see BIRTHDAY_FORMAT} so the mapper and the rule set cannot disagree about
 * it.
 */
#[Exclude]
final readonly class ProfileData
{
    /**
     * The wire format of {@see $birthday}.
     *
     * Date only, deliberately: the TCA column is `type => datetime` with
     * `format => date` and `dbType => date`, so a time of day cannot be stored,
     * and accepting a full `\DateTimeInterface::ATOM` value would silently
     * discard the part of it a client bothered to send.
     */
    public const BIRTHDAY_FORMAT = 'Y-m-d';

    /**
     * @param string $birthday A {@see BIRTHDAY_FORMAT} date, or `''` for "no birthday".
     */
    public function __construct(
        public string $shortname = '',
        public string $firstname = '',
        public string $lastname = '',
        public string $birthday = '',
        public string $bio = '',
    ) {}

    /**
     * Builds the object from a decoded JSON payload, reading only known keys.
     *
     * Hand written on purpose. `PropertyMapper` is not deprecated in v14, but
     * its failure mode is not a `Result` — `convert()` returns `null` and hides
     * the reason in `getMessages()` — and it is a `SingletonInterface` with
     * mutable message state, which is exactly the state this repository forbids
     * in services. An endpoint whose whole job is to answer "which field is
     * wrong" needs the errors as data.
     *
     * Unknown keys are not an error here: this constructor is the full-submit
     * path, and a payload carrying `uid`, `pid` or anything else simply never
     * reaches a property. The partial-save path is the one that rejects unknown
     * field names, because there the name selects what gets written — see
     * {@see \SBUERK\ModernExtbaseFrontendEdit\Validation\RuleSetInterface}.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            shortname: self::stringValue($data, 'shortname'),
            firstname: self::stringValue($data, 'firstname'),
            lastname: self::stringValue($data, 'lastname'),
            birthday: self::stringValue($data, 'birthday'),
            bio: self::stringValue($data, 'bio'),
        );
    }

    /**
     * Reads one key as a string, normalising anything else to `''`.
     *
     * JSON yields more than strings, and a payload carrying `{"bio": 42}` or
     * `{"bio": []}` must not reach a `string` constructor parameter. Casting is
     * not an option either — `(string)[]` emits an "Array to string conversion"
     * warning, and this repository's suites fail on warnings.
     *
     * The two cases are kept apart on purpose. An **absent** key yields
     * `$absentValue`, which is the property's declared default and a legitimate
     * state. A key that is **present but not a string** yields `''`, which is
     * not a fallback but a rejection handed to the rule set: a required field
     * then fails its `NotEmptyValidator` and a select fails its
     * `ChoiceValidator`, rather than this method quietly substituting a value
     * that would validate.
     *
     * @param array<string, mixed> $data
     */
    private static function stringValue(array $data, string $key, string $absentValue = ''): string
    {
        if (!array_key_exists($key, $data)) {
            return $absentValue;
        }

        return is_string($data[$key]) ? $data[$key] : '';
    }
}
