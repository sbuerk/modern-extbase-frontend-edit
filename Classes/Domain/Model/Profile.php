<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Model;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * A profile, the aggregate root of the frontend edit feature.
 *
 * A model is data, not a service: `#[Exclude]` keeps it out of the dependency
 * injection container, which would otherwise pick it up through the resource
 * loading in `Configuration/Services.php`.
 *
 * The class is `final` but not `readonly`, and its properties are mutable: the
 * Extbase data mapper creates the instance without calling the constructor and
 * assigns the properties by reflection, which a readonly property does not
 * allow.
 *
 * No Extbase attribute is used anywhere in this class. `#[Cascade]`,
 * `#[Validate]` and `#[FileUpload]` have no spelling that is both valid on
 * TYPO3 v13 and free of deprecations on v14, and this repository fails a test
 * run on any deprecation — see
 * `docs/architecture/version-neutral-attributes.md`.
 */
#[Exclude]
final class Profile extends AbstractEntity
{
    protected string $shortname = '';

    protected string $firstname = '';

    protected string $lastname = '';

    /**
     * The persisted image relation.
     *
     * The type is exactly `\TYPO3\CMS\Extbase\Domain\Model\FileReference` and
     * must never be narrowed to a subclass of it. The modern Extbase upload API
     * compares the declared property type by class name identity (`===`/`!==`,
     * never `instanceof`) in `FileHandlingService` and in
     * `FileUploadConfiguration::ensureValidConfiguration()`, so a subclass is
     * either skipped without any error or rejected with a `\RuntimeException`.
     * The read side wrapper is {@see ProfileImage}, which is derived and never
     * stored — see `docs/frontend-edit/image-handling.md`.
     */
    protected ?\TYPO3\CMS\Extbase\Domain\Model\FileReference $image = null;

    protected ?\DateTimeImmutable $birthday = null;

    /**
     * The biography text.
     *
     * The default `''` is deliberate and load-bearing: the database column is a
     * nullable `longtext`, because MySQL rejects a literal `DEFAULT` on
     * `BLOB`/`TEXT`/`JSON` columns (error 1101) and Doctrine emits one
     * unconditionally for string-ish types. The `''` invariant can therefore
     * only be enforced here, not by the schema — see
     * `docs/frontend-edit/domain-schema.md`. Do not "clean this up" into a
     * nullable property, and do not add an `ext_tables.sql` default.
     */
    protected string $bio = '';

    /**
     * The uid of the owning frontend user.
     *
     * A plain integer rather than a mapped relation: ownership is resolved
     * through `Security\ProfileOwnershipResolverInterface`, which speaks in
     * owned sets rather than in a `record -> owner uid` mapping, because the
     * upstream migration target resolves ownership through an n:m table.
     */
    protected int $feUser = 0;

    /**
     * The disabled state of the record.
     *
     * There is deliberately **no** `hidden` entry in our `Configuration/TCA/`
     * files, and adding one would be a duplicate: core auto-creates the column
     * definition from `ctrl.enablecolumns.disabled` in
     * `TcaEnrichment::enrichDisabledField()`, and Extbase's `DataMapFactory`
     * reads the prepared TCA rather than our files, so a `ColumnMap` for this
     * property exists.
     *
     * The property is required, not convenience: toggling the disabled state
     * has no Extbase API at all. The column is only writable if it is mapped to
     * a property, and then it is written like any other scalar.
     */
    protected bool $hidden = false;

    /**
     * @var ObjectStorage<Address>
     */
    protected ObjectStorage $addresses;

    /**
     * @var ObjectStorage<Email>
     */
    protected ObjectStorage $emails;

    public function __construct()
    {
        $this->addresses = new ObjectStorage();
        $this->emails = new ObjectStorage();
    }

    /**
     * Initializes the collections for instances the data mapper creates.
     *
     * `DataMapper::createEmptyObject()` instantiates the model **without**
     * calling the constructor and then calls `initializeObject()` if it exists,
     * before assigning the mapped properties. Both entry points therefore have
     * to leave the typed collection properties initialized; an uninitialized
     * typed property would fatal on first access.
     */
    public function initializeObject(): void
    {
        $this->addresses = new ObjectStorage();
        $this->emails = new ObjectStorage();
    }

    public function getShortname(): string
    {
        return $this->shortname;
    }

    public function setShortname(string $shortname): void
    {
        $this->shortname = $shortname;
    }

    public function getFirstname(): string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): void
    {
        $this->firstname = $firstname;
    }

    public function getLastname(): string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): void
    {
        $this->lastname = $lastname;
    }

    public function getImage(): ?\TYPO3\CMS\Extbase\Domain\Model\FileReference
    {
        return $this->image;
    }

    public function setImage(?\TYPO3\CMS\Extbase\Domain\Model\FileReference $image): void
    {
        $this->image = $image;
    }

    /**
     * The read side view on the image, derived from the file reference.
     *
     * Returns `null` when no image is set. A null object was rejected on
     * purpose: every scalar of it would have to carry a meaningless value, and
     * a template rendering `<img src="{profile.profileImage.publicUrl}">`
     * without a guard would emit an empty `src` rather than nothing at all.
     * `null` makes the "no image" case explicit for Fluid, for a DTO and for a
     * JSON response alike.
     *
     * The value object is built on demand and never stored. A stored transient
     * property would need `#[Transient]`, and no attribute at all is the
     * cheaper answer — see `docs/frontend-edit/image-handling.md`.
     *
     * Note that this resolves the FAL objects behind the reference. It is a
     * read side accessor and expects a persisted reference; calling it on a
     * reference that the upload service has just created, before
     * `persistAll()`, resolves nothing and fails, which is intentional — it is
     * a programming error, not a state to render.
     */
    public function getProfileImage(): ?ProfileImage
    {
        if ($this->image === null) {
            return null;
        }

        return ProfileImage::fromFileReference($this->image);
    }

    public function getBirthday(): ?\DateTimeImmutable
    {
        return $this->birthday;
    }

    public function setBirthday(?\DateTimeImmutable $birthday): void
    {
        $this->birthday = $birthday;
    }

    public function getBio(): string
    {
        return $this->bio;
    }

    public function setBio(string $bio): void
    {
        $this->bio = $bio;
    }

    public function getFeUser(): int
    {
        return $this->feUser;
    }

    public function setFeUser(int $feUser): void
    {
        $this->feUser = $feUser;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): void
    {
        $this->hidden = $hidden;
    }

    /**
     * Returns the live collection, not a copy.
     *
     * That is a requirement, not an implementation detail. `ObjectStorage` has
     * no `sort()`, `move()` or `setOrder()`, and `attach()` on an object that is
     * already contained updates the array element in place without changing the
     * iteration order — so the only way to reorder the collection is to detach
     * every member and re-attach them in the target order, which also resets the
     * internal position counter to `0` and makes Extbase write dense `1..n`
     * sorting values. A getter returning a clone or an array would silently
     * break that, and the reorder would look like it worked while producing the
     * old order in the database. See
     * `docs/frontend-edit/persistence-and-sorting.md`.
     *
     * @return ObjectStorage<Address>
     */
    public function getAddresses(): ObjectStorage
    {
        return $this->addresses;
    }

    /**
     * @param ObjectStorage<Address> $addresses
     */
    public function setAddresses(ObjectStorage $addresses): void
    {
        $this->addresses = $addresses;
    }

    public function addAddress(Address $address): void
    {
        $this->addresses->attach($address);
    }

    /**
     * Detaches the address from the collection.
     *
     * Detaching only unwires the child: Extbase writes `0` into the parent
     * pointer and into the sorting column and leaves the row itself alone. The
     * row is deleted explicitly by the edit service before `persistAll()`. This
     * is why there is no `#[Cascade]` on the collection above — it has no
     * version neutral spelling, and it would not cover the file reference case
     * either. Do not add it.
     */
    public function removeAddress(Address $address): void
    {
        $this->addresses->detach($address);
    }

    /**
     * Returns the live collection, not a copy — see {@see getAddresses()}.
     *
     * @return ObjectStorage<Email>
     */
    public function getEmails(): ObjectStorage
    {
        return $this->emails;
    }

    /**
     * @param ObjectStorage<Email> $emails
     */
    public function setEmails(ObjectStorage $emails): void
    {
        $this->emails = $emails;
    }

    public function addEmail(Email $email): void
    {
        $this->emails->attach($email);
    }

    /**
     * Detaches the email from the collection — see {@see removeAddress()} for
     * why the row still has to be deleted explicitly.
     */
    public function removeEmail(Email $email): void
    {
        $this->emails->detach($email);
    }
}
