<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Repository;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Display repository for {@see Profile} records.
 *
 * This is the repository the `list` and `show` plugins use. It sees visible
 * records only: hidden, not yet started, expired and access protected records
 * are filtered out by the enable field constraints, exactly as any other
 * frontend output would, and the configured `persistence.storagePid` applies.
 *
 * Its counterpart is
 * {@see \SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\ProfileEditRepository},
 * which additionally sees hidden records. The two exist as separate classes
 * rather than as one repository with a switchable mode, because a mode is
 * request state on a shared service: whoever flips it last wins for every later
 * caller in the same request.
 *
 * ## Why the defaults are not restated in an `initializeObject()`
 *
 * Assigning `setIgnoreEnableFields(false)` and friends to a settings object and
 * handing it to `setDefaultQuerySettings()` looks like a documentation comment
 * that happens to be code, and it is not: `setDefaultQuerySettings()` makes
 * `createQuery()` clone *that* object for every later query, so the
 * `persistence.storagePid` resolved when this shared repository was first
 * instantiated is frozen for the rest of the request. Without it,
 * `QueryFactory::create()` reads the framework configuration per query, which
 * is what makes two plugins with different storage pages on one page behave
 * correctly. Restating the defaults would therefore change behaviour while
 * appearing to change nothing, which is the worst kind of comment.
 *
 * The edit repositories avoid the same trap from the other side: they relax the
 * enable fields per query rather than repository-wide, so their inherited
 * finders stay visible-only as well.
 *
 * @extends Repository<Profile>
 */
final class ProfileRepository extends Repository {}
