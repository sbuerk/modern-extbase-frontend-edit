<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit;

use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Shared base of the repositories that back the edit plugin.
 *
 * An edit repository sees records an editor has disabled, so the edit plugin
 * can show and un-hide them. Its display counterpart below
 * `Domain\Repository\` does not. Both address the same table.
 *
 * ## The hidden-inclusive path is opt-in, and that is the design
 *
 * The obvious implementation relaxes the enable fields once, in an
 * `initializeObject()`, and hands the result to `setDefaultQuerySettings()`.
 * That is deliberately **not** done here, for two independent reasons.
 *
 * The first is disclosure. `setDefaultQuerySettings()` makes `createQuery()`
 * clone that object for every query the repository will ever build, so *every*
 * finder becomes hidden-inclusive — including `findAll()`, `findByUid()` and
 * the `findBy*()` magic inherited from `Repository`, none of which take an
 * owner constraint. Nothing but a docblock would then stand between a future
 * caller and a result set containing other people's disabled records. With the
 * relaxation on {@see createEditQuery()} instead, **the inherited finders stay
 * visible-only**, and reaching a hidden record requires writing a method that
 * says so. A leak has to be opted into rather than merely forgotten.
 *
 * The second is that `setDefaultQuerySettings()` freezes the query settings
 * `QueryFactory::create()` resolved when this shared repository was first
 * instantiated — `persistence.storagePid` included — for the rest of the
 * request. Building the settings per query keeps the framework configuration
 * authoritative, which matters as soon as two plugins with different storage
 * pages appear on one page.
 *
 * ## No dependencies, hence no `inject*()` method
 *
 * An abstract class must not use constructor injection, because its constructor
 * is part of the API of every extending class; it uses `#[Required]`
 * `inject*()` methods instead. This base needs neither: everything it does goes
 * through `Repository::createQuery()`, whose collaborators the base class
 * already receives through its own `inject*()` methods. The extending classes
 * keep their constructor for the one thing they must do — naming the model
 * class, which cannot be derived for this namespace.
 *
 * The class is not `readonly`, and neither are the classes extending it: PHP
 * requires the whole hierarchy to agree, and `Repository` is not `readonly`.
 *
 * @template T of DomainObjectInterface
 * @extends Repository<T>
 */
abstract class AbstractEditRepository extends Repository
{
    /**
     * A query that additionally sees records an editor has disabled, and
     * nothing else on top of a normal frontend query.
     *
     * Both settings calls are required, and the second one is the line someone
     * will try to remove as redundant.
     *
     * `setIgnoreEnableFields(true)` **alone** makes
     * `Typo3DbQueryParser::getFrontendConstraintStatement()` take its `else`
     * branch and reduce the whole visibility constraint to `deleted = 0`, which
     * drops `starttime`, `endtime` and `fe_group` along with `hidden`. With
     * `setEnableFieldsToBeIgnored(['disabled'])` set, the constraint is instead
     * built by `PageRepository::getDefaultConstraints()` with `disabled` — and
     * only `disabled` — excluded, so the remaining enable fields and the
     * workspace constraints keep applying. A record outside its
     * `starttime`/`endtime` window or behind an `fe_group` the caller is not in
     * therefore stays invisible here too.
     *
     * `includeDeleted` stays `false`, and is stated rather than left implicit:
     * in the frontend it would additionally require `ignoreEnableFields`, and
     * that combination throws `InconsistentQuerySettingsException` (1460975922).
     *
     * The settings object belongs to this one query. Everything not touched
     * here — the storage page ids, `respectStoragePage` including its handling
     * of static and root level tables, the language aspect — arrives from
     * `QueryFactory::create()` and is left alone.
     *
     * @return QueryInterface<T>
     */
    protected function createEditQuery(): QueryInterface
    {
        $query = $this->createQuery();

        $querySettings = $query->getQuerySettings();
        $querySettings->setIgnoreEnableFields(true);
        $querySettings->setEnableFieldsToBeIgnored(['disabled']);
        $querySettings->setIncludeDeleted(false);

        return $query;
    }
}
