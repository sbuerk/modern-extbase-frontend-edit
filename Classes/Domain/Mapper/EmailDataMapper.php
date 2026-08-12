<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Maps an {@see EmailData} onto an {@see Email}.
 *
 * Structurally identical to {@see AddressDataMapper}, and deliberately a second
 * class rather than a shared generic one: the two differ in their property set,
 * their model type and their payload type, and PHP has no generics to abstract
 * that over. A common base taking `mixed` would trade three type checked
 * `switch` arms for an untyped one and hide exactly the dispatch this class
 * exists to make explicit.
 *
 * ## `pid` and `uid` cannot be written from a payload
 *
 * The only path from a payload value to the model is {@see applyProperty()},
 * whose dispatch is a closed `switch` over {@see WRITABLE_PROPERTIES}; `pid`,
 * `uid`, `hidden` and every unknown name fall into `default` and throw.
 * `setPid()` is called in exactly one place, in {@see mapCollection()}, with
 * `$parent->getPid()` off the resolved parent record and never with anything
 * DTO derived — `Backend::determineStoragePageIdForNewRecord()` prefers the
 * object's own pid over all configuration, so a payload controlled pid would be
 * a write-anywhere primitive. `uid` has no setter on `AbstractDomainObject` and
 * this namespace contains no `_setProperty()` call. See
 * `docs/frontend-edit/authorization.md`.
 */
final readonly class EmailDataMapper
{
    /**
     * The properties this mapper is able to write, in mapping order.
     *
     * The mapper's dispatch table, kept from drifting away from
     * `EmailRuleSet::rules()` by a test asserting that every rule set key
     * appears here — see {@see AddressDataMapper::WRITABLE_PROPERTIES} for why
     * that is the direction worth asserting.
     *
     * @var list<string>
     */
    public const WRITABLE_PROPERTIES = [
        'type',
        'email',
    ];

    /**
     * Applies every writable property of the payload to the model.
     *
     * The model is mutated in place and nothing is returned: an Extbase entity
     * has an identity and the persistence session keys on the instance.
     */
    public function map(EmailData $data, Email $email): void
    {
        foreach (self::WRITABLE_PROPERTIES as $property) {
            $this->applyProperty($email, $property, $this->valueOf($data, $property));
        }
    }

    /**
     * Applies a single property, leaving every other one untouched.
     *
     * This is what a partial save uses. `$value` is `mixed` because a partial
     * save carries the raw decoded payload value rather than a DTO; the type is
     * asserted here, strictly and without coercion.
     */
    public function applyProperty(Email $email, string $property, mixed $value): void
    {
        switch ($property) {
            case 'type':
                $email->setType($this->requireString($property, $value));
                break;
            case 'email':
                $email->setEmail($this->requireString($property, $value));
                break;
            default:
                $this->unknownProperty($property);
        }
    }

    /**
     * Builds the intended set of email addresses of a profile, in the intended
     * order.
     *
     * Same contract as {@see AddressDataMapper::mapCollection()}, which
     * documents it in full: a positive integer key addresses an existing record
     * and must be present in `$existing`, any other key creates a new one,
     * `$existing` is the owner constrained set the caller resolved through the
     * edit repository rather than off the parent aggregate, and a record absent
     * from `$submitted` is left alone for the write path to remove.
     *
     * @param array<int|string, EmailData> $submitted keyed by uid for existing children, anything else for new ones
     * @param list<Email> $existing the owner constrained set of persisted children
     * @return ObjectStorage<Email> the intended set, in the intended order
     */
    public function mapCollection(Profile $parent, array $submitted, array $existing): ObjectStorage
    {
        $existingByUid = [];
        foreach ($existing as $email) {
            $uid = $email->getUid();
            // An unpersisted entity has no uid and is therefore unaddressable
            // by a client.
            if ($uid !== null) {
                $existingByUid[$uid] = $email;
            }
        }

        /** @var ObjectStorage<Email> $intended */
        $intended = new ObjectStorage();
        foreach ($submitted as $key => $data) {
            $email = $this->resolveTarget($parent, $existingByUid, $key);
            $this->map($data, $email);
            $intended->attach($email);
        }

        return $intended;
    }

    /**
     * @param array<int, Email> $existingByUid
     */
    private function resolveTarget(Profile $parent, array $existingByUid, int|string $key): Email
    {
        if (is_int($key) && $key > 0) {
            if (!isset($existingByUid[$key])) {
                throw new \InvalidArgumentException(
                    sprintf('Email %d is not part of the addressable set.', $key),
                    1786492123
                );
            }

            return $existingByUid[$key];
        }

        $email = new Email();
        $parentPid = $parent->getPid();
        if ($parentPid !== null) {
            // Server side, from the resolved parent record. Never from a
            // payload — see the class docblock.
            $email->setPid($parentPid);
        }

        return $email;
    }

    private function valueOf(EmailData $data, string $property): mixed
    {
        return match ($property) {
            'type' => $data->type,
            'email' => $data->email,
            default => $this->unknownProperty($property),
        };
    }

    private function requireString(string $property, mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf('Email property "%s" expects a string, %s given.', $property, get_debug_type($value)),
                1786492122
            );
        }

        return $value;
    }

    private function unknownProperty(string $property): never
    {
        throw new \InvalidArgumentException(
            sprintf('Email has no writable property "%s".', $property),
            1786492121
        );
    }
}
