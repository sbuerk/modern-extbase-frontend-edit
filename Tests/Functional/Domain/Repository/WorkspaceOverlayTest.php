<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\ProfileEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;

/**
 * Workspace behaviour of the repositories.
 *
 * The tables are workspace aware (`ctrl.versioningWS`, mandatory on the inline
 * children since Deprecation #106821), so a draft version of a profile is a row
 * of the same table, distinguished only by its `t3ver_*` columns. Two things
 * therefore have to hold, and both of them are silent when they do not:
 *
 * - In the **live** workspace a draft must be invisible, and the live values
 *   must be what is read. A repository leaking `t3ver_oid > 0` rows shows
 *   unpublished content on the public site.
 * - Under a **workspace preview** the draft must be what is read, through the
 *   overlay `Typo3DbBackend` applies with `PageRepository::versionOL()` — the
 *   draft row is never selected directly, because
 *   `Typo3DbQueryParser::getAdditionalWhereClause()` constrains every query on
 *   a workspace aware table to `t3ver_oid = 0`.
 *
 * The preview aspect is set on the `Context` inside the frontend environment
 * rather than through the environment builder, which offers a workspace only
 * for backend environments. `execute()` restores the whole context afterwards,
 * the aspect included.
 *
 * What this does **not** cover is writing in a workspace. Extbase persistence
 * is workspace blind — `Typo3DbBackend::addRow()`/`updateRow()` issue plain
 * statements against the live row — which is why the design refuses such writes
 * with a guard rather than supporting them. That guard is not part of this
 * change and neither is its test.
 */
final class WorkspaceOverlayTest extends AbstractProfileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/WorkspaceProfiles.csv');
    }

    #[Test]
    public function draftVersionIsInvisibleAndLiveValuesAreReadInTheLiveWorkspace(): void
    {
        $uids = [];
        $shortname = null;
        $this->executeInFrontendContext(function () use (&$uids, &$shortname): void {
            $uids = $this->uids($this->get(ProfileRepository::class)->findAll());

            $profile = $this->get(ProfileRepository::class)->findByUid(30);
            $this->assertInstanceOf(Profile::class, $profile);
            $shortname = $profile->getShortname();
        });

        $this->assertSame([30], $uids);
        $this->assertSame('live', $shortname);
    }

    /**
     * The edit repository relaxes `disabled` and nothing else, so a draft is
     * invisible to it as well.
     *
     * This particular row is excluded by `t3ver_oid = 0`, which
     * `Typo3DbQueryParser::getAdditionalWhereClause()` adds unconditionally for
     * every workspace aware table — the query settings play no part in it, and
     * this test therefore stays green when they are wrong. The row that does
     * depend on them is one *created* in a workspace, which carries
     * `t3ver_oid = 0` and `t3ver_wsid > 0` and is filtered by the workspace
     * constraints of `PageRepository::getDefaultConstraints()` alone; it is
     * covered in
     * {@see RecordVisibilityTest::editRepositoryStillHidesScheduledExpiredAndWorkspaceProfiles()}.
     */
    #[Test]
    public function draftVersionIsInvisibleToTheEditRepositoryInTheLiveWorkspace(): void
    {
        $uids = [];
        $shortname = null;
        $this->executeInFrontendContext(function () use (&$uids, &$shortname): void {
            $uids = $this->uids($this->get(ProfileEditRepository::class)->findAllByFrontendUser(1));

            $profile = $this->get(ProfileEditRepository::class)->findByUidIncludingHidden(30);
            $this->assertInstanceOf(Profile::class, $profile);
            $shortname = $profile->getShortname();
        });

        $this->assertSame([30], $uids);
        $this->assertSame('live', $shortname);
    }

    /**
     * Under a workspace preview the live row is selected and then overlaid with
     * its draft, so the uid stays the live one while the values are the drafted
     * ones. A test asserting only the uid would not notice a missing overlay at
     * all.
     */
    #[Test]
    public function draftValuesAreOverlaidOntoTheLiveRecordInAWorkspacePreview(): void
    {
        $uids = [];
        $shortname = null;
        $firstname = null;
        $this->executeInFrontendContext(function () use (&$uids, &$shortname, &$firstname): void {
            $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));

            $uids = $this->uids($this->get(ProfileRepository::class)->findAll());

            $profile = $this->get(ProfileRepository::class)->findByUid(30);
            $this->assertInstanceOf(Profile::class, $profile);
            $shortname = $profile->getShortname();
            $firstname = $profile->getFirstname();
        });

        $this->assertSame([30], $uids);
        $this->assertSame('draft', $shortname);
        $this->assertSame('Augusta', $firstname);
    }

    /**
     * @param iterable<DomainObjectInterface> $records
     * @return list<int>
     */
    private function uids(iterable $records): array
    {
        $uids = [];
        foreach ($records as $record) {
            $uids[] = (int)$record->getUid();
        }
        sort($uids);

        return $uids;
    }
}
