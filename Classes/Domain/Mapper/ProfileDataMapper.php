<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Maps a validated {@see ProfileData} onto a {@see Profile}.
 *
 * The service is stateless and holds nothing derived from a request: the model,
 * the payload object and the resolved children are arguments on every call.
 *
 * **It maps and nothing else.** No `add()`, no `update()`, no `remove()`, no
 * `persistAll()`, no reordering of a live collection and no orphan removal —
 * all of that belongs to the write path, and separating them is what makes this
 * class unit testable without a database.
 *
 * ## `pid` and `uid` cannot be written from a payload, structurally
 *
 * This is the last layer that could let a payload controlled `pid` through, so
 * it is closed rather than guarded:
 *
 * 1. There is exactly one path from a payload value to the model,
 *    {@see applyProperty()}. Its dispatch is a closed `switch` over
 *    {@see WRITABLE_PROPERTIES}, and `pid`, `uid`, `feUser` and `hidden` fall
 *    into `default`, which throws. Making a property writable means adding a
 *    `case` with a setter call, so the omission cannot happen by accident.
 * 2. {@see map()} does not read the DTO dynamically. Every value is fetched by
 *    an explicit `match` arm, so a property the DTO grows later is invisible
 *    here until someone adds the arm.
 * 3. `setPid()` is never called in this class. New children get the parent's
 *    pid, and only there — see {@see AddressDataMapper::mapCollection()}.
 * 4. `uid` has no setter on `AbstractDomainObject` at all; the only way in is
 *    `_setProperty()`, and this namespace contains no such call.
 *
 * The reason this matters is not defensive tidiness:
 * `Backend::determineStoragePageIdForNewRecord()` prefers the object's own pid
 * over `newRecordStoragePid` and over `persistence.storagePid`, so a record
 * whose pid a request was allowed to set is written wherever the request said —
 * no amount of configuration protects it. See
 * `docs/frontend-edit/authorization.md`.
 *
 * ## What is deliberately not mapped
 *
 * `image` is not a payload property: the file reference is written by the
 * upload path, which resolves a `sys_file_reference` and deletes the replaced
 * one, and none of that is a value assignment. `feUser` and `hidden` are not
 * writable either — ownership is resolved from the session, and publishing is a
 * dedicated action with its own ownership assertion.
 */
final readonly class ProfileDataMapper
{
    /**
     * The properties this mapper is able to write, in mapping order.
     *
     * This is the mapper's dispatch table. It is a second list next to
     * `ProfileRuleSet::rules()`, which `docs/frontend-edit/dto-and-validation.md`
     * makes the single whitelist of addressable field names, and the two are
     * kept from drifting by a test asserting that every rule set key appears
     * here. That is the direction worth asserting: a validated property this
     * mapper cannot write throws at runtime, whereas a property that carries no
     * rules is not a hole — a partial save naming it is rejected by the rule
     * set before it ever reaches this class. The two lists happen to be equal
     * today, and the assertion is what keeps them that way.
     *
     * @var list<string>
     */
    public const WRITABLE_PROPERTIES = [
        'shortname',
        'firstname',
        'lastname',
        'birthday',
        'bio',
    ];

    public function __construct(
        private AddressDataMapper $addressDataMapper,
        private EmailDataMapper $emailDataMapper,
    ) {}

    /**
     * Applies every writable property of the payload to the model.
     *
     * The model is mutated in place and nothing is returned: an Extbase entity
     * has an identity, the persistence session keys on the instance, and a
     * mapper handing back a different object would silently detach the result
     * from the aggregate the caller resolved through the ownership rule.
     *
     * Child collections are not mapped here. `ProfileData` does not carry the
     * association between a payload entry and an existing child — the payload
     * keys do, and they do not survive being flattened into a list — so the
     * collections go through {@see mapAddresses()} and {@see mapEmails()},
     * which also need the owner constrained set of existing children that only
     * the caller can resolve.
     */
    public function map(ProfileData $data, Profile $profile): void
    {
        foreach (self::WRITABLE_PROPERTIES as $property) {
            $this->applyProperty($profile, $property, $this->valueOf($data, $property));
        }
    }

    /**
     * Applies a single property, leaving every other one untouched.
     *
     * This is what a partial save uses, and it is the same code the full path
     * runs — {@see map()} is a loop over this method, so per property behaviour
     * exists once. `$value` is `mixed` because a partial save carries the raw
     * decoded payload value rather than a DTO: the type is asserted here rather
     * than by a constructor signature.
     *
     * The assertions are strict and never coerce. Validation has already run
     * when this is reached, so a wrong type is a programming error or a payload
     * shape the rules did not cover, and silently casting `['x']` or `42` into
     * a string would turn both into stored data.
     */
    public function applyProperty(Profile $profile, string $property, mixed $value): void
    {
        switch ($property) {
            case 'shortname':
                $profile->setShortname($this->requireString($property, $value));
                break;
            case 'firstname':
                $profile->setFirstname($this->requireString($property, $value));
                break;
            case 'lastname':
                $profile->setLastname($this->requireString($property, $value));
                break;
            case 'birthday':
                $profile->setBirthday($this->requireDate($property, $value));
                break;
            case 'bio':
                $profile->setBio($this->requireText($property, $value));
                break;
            default:
                $this->unknownProperty($property);
        }
    }

    /**
     * Builds the intended set of addresses, in the intended order.
     *
     * Delegates to {@see AddressDataMapper::mapCollection()}, which documents
     * the contract in full. Exposed here so the write path injects one mapper
     * for the whole aggregate rather than three.
     *
     * @param array<int|string, AddressData> $submitted keyed by uid for existing children, anything else for new ones
     * @param list<Address> $existing the owner constrained set of persisted children
     * @return ObjectStorage<Address>
     */
    public function mapAddresses(Profile $profile, array $submitted, array $existing): ObjectStorage
    {
        return $this->addressDataMapper->mapCollection($profile, $submitted, $existing);
    }

    /**
     * Builds the intended set of email addresses, in the intended order.
     *
     * @param array<int|string, EmailData> $submitted keyed by uid for existing children, anything else for new ones
     * @param list<Email> $existing the owner constrained set of persisted children
     * @return ObjectStorage<Email>
     */
    public function mapEmails(Profile $profile, array $submitted, array $existing): ObjectStorage
    {
        return $this->emailDataMapper->mapCollection($profile, $submitted, $existing);
    }

    private function valueOf(ProfileData $data, string $property): mixed
    {
        return match ($property) {
            'shortname' => $data->shortname,
            'firstname' => $data->firstname,
            'lastname' => $data->lastname,
            // The raw wire string, deliberately unparsed. Converting here would
            // give the full path a different conversion than the partial one,
            // which only ever sees the raw string — and two parsers for one
            // property is exactly the drift this dispatch exists to prevent.
            // That is also why the DTO carries no parsing helper of its own:
            // this mapper is the single place where the wire format becomes a
            // date.
            'birthday' => $data->birthday,
            'bio' => $data->bio,
            default => $this->unknownProperty($property),
        };
    }

    private function requireString(string $property, mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf('Profile property "%s" expects a string, %s given.', $property, get_debug_type($value)),
                1786492103
            );
        }

        return $value;
    }

    /**
     * Normalizes a text property, keeping the model's `''` invariant.
     *
     * `bio` is `string` on the model with a default of `''`, because the column
     * is a nullable `longtext` — MySQL rejects a literal default on a TEXT
     * column, so the invariant can only be enforced in PHP. A cleared textarea
     * arrives as `null` or `''` depending on how the client serializes it, and
     * both have to end up as `''`; passing `null` through would be a `TypeError`
     * on `setBio()`, and making the property nullable to accommodate it would
     * move the invariant out of the model, which
     * `docs/frontend-edit/domain-schema.md` explicitly rules out.
     */
    private function requireText(string $property, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return $this->requireString($property, $value);
    }

    /**
     * Converts the wire value of a date property to the model's
     * `?\DateTimeImmutable`.
     *
     * The DTO carries `birthday` as a raw string rather than a parsed object,
     * because a partial save never builds a DTO and would otherwise validate a
     * string against rules written for an object. Conversion is therefore the
     * mapper's job, and this is the one place it happens — both for a full
     * submit and for a partial one.
     *
     * The format is not restated here: {@see ProfileData::BIRTHDAY_FORMAT} is
     * the pinned wire format, so the mapper, the DTO and the rule set cannot
     * disagree about it. `!` resets the fields the format does not carry, so
     * the result is midnight of that day rather than midnight plus the current
     * time of day — which matters, because the column is `dbType => 'date'` and
     * a stray time component would be discarded silently on write and
     * inconsistently in a comparison before it.
     *
     * `''` is the "no birthday" wire value, matching the DTO's default; `null`
     * is accepted as the same thing, since a client may serialize an empty date
     * field either way.
     *
     * An unparseable string throws instead of becoming `null`. Turning it into
     * "no birthday" would store a value the user did not enter, and the
     * validation layer rejects such a value before anything reaches here — so
     * arriving with one is a bug, not a user error to absorb.
     *
     * A `\DateTimeInterface` is accepted and normalized as well, for a caller
     * that already holds one; `\DateTime` is converted rather than refused,
     * because the conversion is exact.
     */
    private function requireDate(string $property, mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        $value = $this->requireString($property, $value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . ProfileData::BIRTHDAY_FORMAT, $value);
        if ($date === false) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Profile property "%s" expects a "%s" date, "%s" given.',
                    $property,
                    ProfileData::BIRTHDAY_FORMAT,
                    $value,
                ),
                1786492102
            );
        }

        return $date;
    }

    private function unknownProperty(string $property): never
    {
        throw new \InvalidArgumentException(
            sprintf('Profile has no writable property "%s".', $property),
            1786492101
        );
    }
}
