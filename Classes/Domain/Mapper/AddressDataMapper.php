<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Maps an {@see AddressData} onto an {@see Address}.
 *
 * The service is stateless and holds nothing derived from a request: the model,
 * the payload object and the parent record are arguments on every call. It does
 * not persist — `add()`, `update()`, `remove()` and `persistAll()` belong to the
 * write path, not here, and orphan removal is explicitly not this class's
 * business (see {@see mapCollection()}).
 *
 * ## `pid` and `uid` cannot be written from a payload
 *
 * There is exactly one path from a payload value to the model, and that is
 * {@see applyProperty()}. Its dispatch is a closed `switch` over
 * {@see WRITABLE_PROPERTIES}; every other name — `pid`, `uid`, `hidden`,
 * anything a client invents — falls into `default` and throws. That is a
 * structural guarantee rather than a check that can be forgotten: adding a
 * writable property means writing a `case`, and `pid` has no setter call to
 * write it into.
 *
 * `setPid()` is called in exactly one place, in {@see mapCollection()}, with
 * `$parent->getPid()` — a value read off the already resolved, owner
 * constrained parent record. No argument of that call is derived from a DTO. It
 * is needed because `Backend::determineStoragePageIdForNewRecord()` prefers the
 * object's own pid over every configuration source, so a new child that does
 * not get the parent's pid here lands wherever `persistence.storagePid`
 * happens to point — and a payload controlled pid would be a write-anywhere
 * primitive. See `docs/frontend-edit/authorization.md`.
 *
 * `uid` is not writable at all from here: `AbstractDomainObject` has no
 * `setUid()`, the only way in is `_setProperty()`, and this namespace contains
 * no such call.
 */
final readonly class AddressDataMapper
{
    /**
     * The properties this mapper is able to write, in mapping order.
     *
     * This is the mapper's dispatch table and a second list next to the rule
     * set, which `docs/frontend-edit/dto-and-validation.md` makes the single
     * whitelist. The two are kept from drifting by a test asserting that every
     * key of `AddressRuleSet::rules()` appears here — the dangerous direction,
     * because a validated property the mapper cannot write is an exception at
     * runtime, while the reverse is not a hole: a partial save naming a
     * property the rule set does not know is rejected before it reaches this
     * class.
     *
     * @var list<string>
     */
    public const WRITABLE_PROPERTIES = [
        'type',
        'line1',
        'line2',
    ];

    /**
     * Applies every writable property of the payload to the model.
     *
     * The model is mutated in place and nothing is returned: an Extbase entity
     * has an identity, the persistence session keys on the instance, and a
     * mapper that handed back a different object would silently detach the
     * result from the aggregate it was resolved from.
     *
     * Per property behaviour is not duplicated here — the full path is this
     * loop over {@see applyProperty()}, the partial path is a single call of
     * it.
     */
    public function map(AddressData $data, Address $address): void
    {
        foreach (self::WRITABLE_PROPERTIES as $property) {
            $this->applyProperty($address, $property, $this->valueOf($data, $property));
        }
    }

    /**
     * Applies a single property, leaving every other one untouched.
     *
     * This is what a partial save uses. `$value` is deliberately `mixed`: a
     * partial save carries the raw decoded payload value rather than a DTO, so
     * the type is asserted here instead of by a constructor signature. The
     * assertions are strict and never coerce — validation has already run when
     * this is reached, so a wrong type at this point is a programming error or
     * a payload shape validation did not cover, and both deserve to be loud.
     */
    public function applyProperty(Address $address, string $property, mixed $value): void
    {
        switch ($property) {
            case 'type':
                $address->setType($this->requireString($property, $value));
                break;
            case 'line1':
                $address->setLine1($this->requireString($property, $value));
                break;
            case 'line2':
                $address->setLine2($this->requireString($property, $value));
                break;
            default:
                $this->unknownProperty($property);
        }
    }

    /**
     * Builds the intended set of addresses of a profile, in the intended order.
     *
     * **This returns a set. It does not assign it, reorder anything or delete
     * anything** — the sorting service and the orphan removal that act on the
     * result are part of the write path, deliberately not of this class.
     * Reordering the parent's live collection means detaching every member and
     * re-attaching it, and doing that here would make the mapper the thing that
     * decides sorting; see `docs/frontend-edit/persistence-and-sorting.md`.
     *
     * `$submitted` is keyed by the identity the client claims for each entry,
     * which is exactly the shape a JSON object of children decodes into:
     *
     * - a **positive integer key** addresses an existing address and is looked
     *   up in `$existing`. A key that is not in `$existing` throws: `$existing`
     *   is the owner constrained set the caller resolved, so an unknown key is
     *   either another user's record or one that does not exist, and per
     *   `docs/frontend-edit/authorization.md` both must produce the same
     *   response. It is never silently skipped.
     * - **any other key** — a string such as `new-1`, or `0` — creates a new
     *   address. The key is used for nothing but this distinction and never
     *   reaches the model.
     *
     * `$existing` is passed in rather than read off `$parent->getAddresses()`.
     * That is required, not a preference: relations are loaded through query
     * settings the data mapper builds from scratch, so hidden children are
     * absent from the parent's collection no matter how the parent was fetched.
     * The caller assembles the set from the edit repository.
     *
     * An address present in `$existing` but absent from `$submitted` is not in
     * the returned set and is not touched here. Detecting and deleting it is
     * the write path's job — a detach alone leaves the row behind with a
     * cleared parent pointer and `sorting = 0`.
     *
     * @param array<int|string, AddressData> $submitted keyed by uid for existing children, anything else for new ones
     * @param list<Address> $existing the owner constrained set of persisted children
     * @return ObjectStorage<Address> the intended set, in the intended order
     */
    public function mapCollection(Profile $parent, array $submitted, array $existing): ObjectStorage
    {
        $existingByUid = [];
        foreach ($existing as $address) {
            $uid = $address->getUid();
            // An unpersisted entity has no uid and is therefore unaddressable
            // by a client. Skipping it here means a payload can only ever reach
            // it by creating a new record, which is the correct outcome.
            if ($uid !== null) {
                $existingByUid[$uid] = $address;
            }
        }

        /** @var ObjectStorage<Address> $intended */
        $intended = new ObjectStorage();
        foreach ($submitted as $key => $data) {
            $address = $this->resolveTarget($parent, $existingByUid, $key);
            $this->map($data, $address);
            $intended->attach($address);
        }

        return $intended;
    }

    /**
     * @param array<int, Address> $existingByUid
     */
    private function resolveTarget(Profile $parent, array $existingByUid, int|string $key): Address
    {
        if (is_int($key) && $key > 0) {
            if (!isset($existingByUid[$key])) {
                throw new \InvalidArgumentException(
                    sprintf('Address %d is not part of the addressable set.', $key),
                    1786492113
                );
            }

            return $existingByUid[$key];
        }

        $address = new Address();
        $parentPid = $parent->getPid();
        if ($parentPid !== null) {
            // Server side, from the resolved parent record. Never from a
            // payload — see the class docblock.
            $address->setPid($parentPid);
        }

        return $address;
    }

    private function valueOf(AddressData $data, string $property): mixed
    {
        return match ($property) {
            'type' => $data->type,
            'line1' => $data->line1,
            'line2' => $data->line2,
            default => $this->unknownProperty($property),
        };
    }

    private function requireString(string $property, mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf('Address property "%s" expects a string, %s given.', $property, get_debug_type($value)),
                1786492112
            );
        }

        return $value;
    }

    private function unknownProperty(string $property): never
    {
        throw new \InvalidArgumentException(
            sprintf('Address has no writable property "%s".', $property),
            1786492111
        );
    }
}
