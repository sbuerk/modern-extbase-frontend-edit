<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Edit repository for {@see Profile} records.
 *
 * Same table as
 * {@see \SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository},
 * but with methods that additionally see records an editor has disabled, so the
 * edit plugin can show and un-hide them.
 *
 * Only the methods declared below take that path — they build their query with
 * {@see AbstractEditRepository::createEditQuery()}. **The finders inherited
 * from `Repository` are deliberately left visible-only**, so no caller reaches
 * a hidden record without asking for one; see the base class for why the
 * relaxation is not a default query setting.
 *
 * Both methods below are owner constrained or documented as operating on an
 * already-owned set. The edit flow enters persistence through
 * {@see \SBUERK\ModernExtbaseFrontendEdit\Security\ProfileOwnershipResolverInterface},
 * whose argument comes from the session, and a client supplied uid only ever
 * filters the set the server already built.
 *
 * @extends AbstractEditRepository<Profile>
 */
final class ProfileEditRepository extends AbstractEditRepository
{
    /**
     * `Repository::__construct()` derives the managed model class from the
     * repository class name — `\Domain\Repository` becomes `\Domain\Model` and
     * the `Repository` suffix is dropped
     * (`ClassNamingUtility::translateRepositoryNameToModelName()`). For a
     * repository in this `Edit\` sub namespace that yields
     * `…\Domain\Model\Edit\ProfileEdit`, which does not exist, so the object
     * type is assigned explicitly. `parent::__construct()` is still called so
     * that future work in the base constructor is not skipped; its result for
     * `objectType` is a pure string operation and is simply overwritten.
     */
    public function __construct()
    {
        parent::__construct();
        $this->objectType = Profile::class;
    }

    /**
     * All profiles owned by the given frontend user, hidden ones included.
     *
     * This is the owner constrained entry point the ownership resolver uses.
     * The `feUser > 0` half of the constraint is not defensive noise: a profile
     * whose owner column is `0` — written by an editor, by an import or by a
     * bug — would otherwise be returned for a caller id of `0`, which is what
     * `UserAspect::get('id')` yields for an anonymous visitor. Denying that in
     * SQL means no caller can reintroduce the hole by forgetting a guard.
     *
     * @return QueryResultInterface<int, Profile>
     */
    public function findAllByFrontendUser(int $frontendUserId): QueryResultInterface
    {
        $query = $this->createEditQuery();

        return $query
            ->matching(
                $query->logicalAnd(
                    $query->equals('feUser', $frontendUserId),
                    $query->greaterThan('feUser', 0),
                )
            )
            ->execute();
    }

    /**
     * Finds a profile by uid, hidden ones included.
     *
     * This deliberately does not delegate to `Repository::findByUid()`, and the
     * reason is not stylistic. `findByUid()` ends up in
     * `Backend::getObjectByIdentifier()`, which builds a *fresh* query through
     * `createQueryForType()`. That query never sees this repository's settings
     * at all — `ignoreEnableFields` is not touched there and stays `false` in
     * the frontend — so a hidden record is simply not found, whatever the
     * repository is configured to do.
     *
     * The uid handed in must already be known to belong to the caller: this
     * method answers "give me this record including hidden", not "may this
     * caller have this record". Resolve the owned set with
     * {@see findAllByFrontendUser()} first and filter it — never pass a request
     * parameter in here.
     */
    public function findByUidIncludingHidden(int $uid): ?Profile
    {
        $query = $this->createEditQuery();

        return $query->matching($query->equals('uid', $uid))->execute()->getFirst();
    }
}
