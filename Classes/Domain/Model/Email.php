<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Model;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * An email address of a {@see Profile}, a manually sorted 1:n child.
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
 * No `#[Validate]` on the `email` property: the attribute has no spelling that
 * is valid on TYPO3 v13 and free of deprecations on v14. Validation rules are
 * carried as data by a rule set instead — see
 * `docs/architecture/version-neutral-attributes.md`.
 *
 * The child carries no back reference to its parent. The parent pointer is
 * written by the Extbase persistence layer from the parent's `ObjectStorage`,
 * and hidden children are read through the dedicated edit repository rather
 * than off the parent aggregate — see
 * `docs/frontend-edit/persistence-and-sorting.md`.
 */
#[Exclude]
final class Email extends AbstractEntity
{
    /**
     * The email address type.
     *
     * The default mirrors the TCA default of the `type` column, which is pinned
     * to `DEFAULT 'others'` in `ext_tables.sql` because the auto-generated
     * definition of a `type=select` column differs between TYPO3 v13 and v14.
     */
    protected string $type = 'others';

    protected string $email = '';

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
     * a property, and then it is written like any other scalar. A hidden child
     * is reachable through its own edit repository, never through the parent's
     * collection — see `docs/frontend-edit/persistence-and-sorting.md`.
     */
    protected bool $hidden = false;

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): void
    {
        $this->hidden = $hidden;
    }
}
