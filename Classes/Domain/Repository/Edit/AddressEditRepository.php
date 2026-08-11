<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Edit repository for {@see Address} records.
 *
 * ## Why this class exists at all
 *
 * The obvious implementation of the edit form reads the children off the
 * already fetched aggregate — `$profile->getAddresses()` — and it silently
 * drops exactly the records the feature is about. Relations are **not** loaded
 * with the query settings of the query that loaded the parent:
 * `DataMapper::getPreparedQuery()` creates a brand new query through
 * `QueryFactory::create()` and only ever sets `respectStoragePage` and
 * `respectSysLanguage` on it. `ignoreEnableFields` is never assigned there, so
 * it keeps the context default — `false` in the frontend. The class docblock of
 * `Typo3QuerySettings` says the same in prose: query settings "are not adhered
 * to when reconstituting relations of entity objects. There a completely new
 * Typo3QuerySettings object is used, with default settings applied."
 *
 * So no matter how the `Profile` was fetched, hidden addresses are absent from
 * its `ObjectStorage`. **The edit flow therefore loads the children through
 * this repository and assembles them itself; it never reads the collection off
 * the parent.** The parent's storage is still what gets *written* — that is
 * where the parent pointer and the manual sorting come from — but it is not
 * what the form is built from.
 *
 * Reading them off `$profile` instead is a one line "cleanup" that produces a
 * form silently missing the records the user disabled, and no test whose
 * fixtures contain only visible children notices.
 *
 * ## What sees hidden records, and what does not
 *
 * Only the two methods declared below, which build their query with
 * {@see AbstractEditRepository::createEditQuery()}. **The finders inherited
 * from `Repository` are deliberately left visible-only.** Both methods are
 * constrained to a parent profile whose ownership the caller has already
 * established through
 * {@see \SBUERK\ModernExtbaseFrontendEdit\Security\ProfileOwnershipResolverInterface}.
 *
 * @extends AbstractEditRepository<Address>
 */
final class AddressEditRepository extends AbstractEditRepository
{
    /**
     * The base constructor derives the model class from the repository class
     * name and would produce `…\Domain\Model\Edit\AddressEdit` for this `Edit\`
     * sub namespace — see {@see ProfileEditRepository::__construct()}.
     */
    public function __construct()
    {
        parent::__construct();
        $this->objectType = Address::class;
    }

    /**
     * All addresses of the given profile, hidden ones included, in the manual
     * sorting order the backend and the frontend share.
     *
     * `profile` and `sorting` are database columns without a model property —
     * the parent pointer is a `passthrough` TCA column and `sorting` is a
     * control field. Extbase resolves an unmapped property name by falling back
     * to `GeneralUtility::camelCaseToLowerCaseUnderscored()`
     * (`DataMapper::convertPropertyNameToColumnName()`), which maps both onto
     * themselves. That fallback is what makes this query possible without
     * putting a back reference to the parent on the child model.
     *
     * The `foreign_table_field` column (`tablenames`) is intentionally **not**
     * part of the constraint: the address table is used by exactly one parent
     * field today, so it would discriminate nothing. The moment a second parent
     * field points at this table, this constraint has to gain it.
     *
     * `profile > 0` excludes orphans. Detaching a child from its parent does
     * not delete the row — it writes `profile = 0` and `sorting = 0` and leaves
     * everything else in place — and without this half of the constraint a
     * caller passing `0` would be handed every orphan in the installation.
     *
     * @return QueryResultInterface<int, Address>
     */
    public function findAllByProfileUid(int $profileUid): QueryResultInterface
    {
        $query = $this->createEditQuery();
        $query->setOrderings(['sorting' => QueryInterface::ORDER_ASCENDING]);

        return $query
            ->matching(
                $query->logicalAnd(
                    $query->equals('profile', $profileUid),
                    $query->greaterThan('profile', 0),
                )
            )
            ->execute();
    }

    /**
     * Finds one address of the given profile by uid, hidden ones included.
     *
     * This deliberately does not delegate to `Repository::findByUid()`, which
     * ends up in `Backend::getObjectByIdentifier()` — a freshly built query
     * that never sees this repository's query settings and can therefore never
     * return a hidden record.
     *
     * The parent uid is not a convenience parameter, it is the authorization
     * constraint, and it is the reason this method takes two arguments instead
     * of one. A child looked up by uid alone is an insecure direct object
     * reference: the uid comes from the request, and trusting the parent
     * pointer of whatever row comes back moves the problem one level down
     * rather than solving it. Here the client uid only *filters* a set the
     * server derived from the session, so a uid belonging to somebody else
     * returns `null` — indistinguishable from a uid that does not exist, which
     * is the point.
     *
     * @param int $profileUid Uid of a profile whose ownership is already established
     */
    public function findByUidAndProfileUidIncludingHidden(int $uid, int $profileUid): ?Address
    {
        $query = $this->createEditQuery();

        return $query
            ->matching(
                $query->logicalAnd(
                    $query->equals('uid', $uid),
                    $query->equals('profile', $profileUid),
                    $query->greaterThan('profile', 0),
                )
            )
            ->execute()
            ->getFirst();
    }
}
