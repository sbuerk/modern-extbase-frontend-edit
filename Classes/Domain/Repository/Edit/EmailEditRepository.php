<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Edit repository for {@see Email} records.
 *
 * ## Why this class exists at all
 *
 * For the same reason {@see AddressEditRepository} does, and the reasoning is
 * written out there in full: query settings do not reach relations.
 * `DataMapper::getPreparedQuery()` builds a completely new query for every
 * relation and never assigns `ignoreEnableFields`, so hidden e-mail addresses
 * are absent from `$profile->getEmails()` however the `Profile` was fetched.
 *
 * **The edit flow loads the children through this repository and assembles them
 * itself; it never reads the collection off the parent.** The parent's
 * `ObjectStorage` remains what gets written — parent pointer and manual sorting
 * come from there — it is just not what the form is built from.
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
 * @extends AbstractEditRepository<Email>
 */
final class EmailEditRepository extends AbstractEditRepository
{
    /**
     * The base constructor derives the model class from the repository class
     * name and would produce `…\Domain\Model\Edit\EmailEdit` for this `Edit\`
     * sub namespace — see {@see ProfileEditRepository::__construct()}.
     */
    public function __construct()
    {
        parent::__construct();
        $this->objectType = Email::class;
    }

    /**
     * All e-mail addresses of the given profile, hidden ones included, in the
     * manual sorting order the backend and the frontend share.
     *
     * `profile` and `sorting` are database columns without a model property and
     * resolve through the unmapped property name fallback of
     * `DataMapper::convertPropertyNameToColumnName()`; `tablenames` is
     * deliberately not constrained; `profile > 0` excludes orphans. All three
     * are explained in {@see AddressEditRepository::findAllByProfileUid()}.
     *
     * @return QueryResultInterface<int, Email>
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
     * Finds one e-mail address of the given profile by uid, hidden ones
     * included.
     *
     * This deliberately does not delegate to `Repository::findByUid()`, which
     * ends up in `Backend::getObjectByIdentifier()` — a freshly built query
     * that never sees this repository's query settings and can therefore never
     * return a hidden record.
     *
     * The parent uid is the authorization constraint rather than a convenience
     * parameter; see
     * {@see AddressEditRepository::findByUidAndProfileUidIncludingHidden()} for
     * why a child is never looked up by uid alone.
     *
     * @param int $profileUid Uid of a profile whose ownership is already established
     */
    public function findByUidAndProfileUidIncludingHidden(int $uid, int $profileUid): ?Email
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
