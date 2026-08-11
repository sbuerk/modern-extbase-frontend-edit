<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\AddressRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\AddressEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\EmailEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\ProfileEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\EmailRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;

/**
 * The visibility split between the display and the edit repositories.
 *
 * This is the load bearing behaviour of the whole feature: an editor has to see
 * and un-hide their own disabled records, while every other reader must not.
 * Both halves of that are silent failure modes — a display repository that
 * shows hidden records discloses content, and an edit repository that does not
 * produces a form which drops exactly the records the user disabled, with no
 * error anywhere.
 *
 * Every test runs inside a frontend environment, which is what makes the enable
 * field constraints the frontend ones — see {@see AbstractProfileTestCase}.
 */
final class RecordVisibilityTest extends AbstractProfileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/Profiles.csv');
    }

    #[Test]
    public function hiddenProfileIsAbsentFromTheDisplayRepositoryAndPresentOnTheEditRepository(): void
    {
        $displayed = [];
        $editable = [];
        $this->executeInFrontendContext(function () use (&$displayed, &$editable): void {
            $displayed = $this->sortedUids($this->get(ProfileRepository::class)->findAll());
            $editable = $this->sortedUids($this->get(ProfileEditRepository::class)->findAllByFrontendUser(1));
        });

        // 2 is hidden, 3 has not started yet, 4 has expired, 8 lives in a
        // workspace. 5 and 6 belong to another owner and to nobody.
        $this->assertSame([1, 5, 6, 7], $displayed);
        $this->assertSame([1, 2, 7], $editable);
    }

    #[Test]
    public function hiddenAddressIsAbsentFromTheDisplayRepositoryAndPresentOnTheEditRepository(): void
    {
        $displayed = [];
        $editable = [];
        $this->executeInFrontendContext(function () use (&$displayed, &$editable): void {
            $displayed = $this->sortedUids($this->get(AddressRepository::class)->findAll());
            $editable = $this->uids($this->get(AddressEditRepository::class)->findAllByProfileUid(1));
        });

        // Addresses 1 to 5 belong to profile 1, 11 to 13 to profile 7. Address
        // 2 is hidden, 4 has not started yet and 5 has expired, so the display
        // repository drops all three of them.
        $this->assertSame([1, 3, 11, 12, 13], $displayed);
        $this->assertSame([1, 2, 3], $editable);
    }

    #[Test]
    public function hiddenEmailIsAbsentFromTheDisplayRepositoryAndPresentOnTheEditRepository(): void
    {
        $displayed = [];
        $editable = [];
        $this->executeInFrontendContext(function () use (&$displayed, &$editable): void {
            $displayed = $this->sortedUids($this->get(EmailRepository::class)->findAll());
            $editable = $this->uids($this->get(EmailEditRepository::class)->findAllByProfileUid(1));
        });

        $this->assertSame([1], $displayed);
        $this->assertSame([1, 2], $editable);
    }

    /**
     * `Repository::findByUid()` ends up in `Backend::getObjectByIdentifier()`,
     * which builds a *fresh* query and therefore never sees the query settings
     * of the repository it was called on. That is the entire reason the edit
     * repositories carry their own uid lookup, and this test is what keeps a
     * future "simplification" of that lookup into `findByUid()` from passing
     * review.
     */
    #[Test]
    public function findByUidCannotFindAHiddenProfileWhileTheEditLookupCan(): void
    {
        $byFindByUid = 'not executed';
        $byEditLookup = 'not executed';
        $this->executeInFrontendContext(function () use (&$byFindByUid, &$byEditLookup): void {
            $byFindByUid = $this->get(ProfileEditRepository::class)->findByUid(2)?->getShortname();
            $byEditLookup = $this->get(ProfileEditRepository::class)->findByUidIncludingHidden(2)?->getShortname();
        });

        $this->assertNull($byFindByUid);
        $this->assertSame('hidden', $byEditLookup);
    }

    #[Test]
    public function findByUidCannotFindAHiddenAddressWhileTheEditLookupCan(): void
    {
        $byFindByUid = 'not executed';
        $byEditLookup = 'not executed';
        $this->executeInFrontendContext(function () use (&$byFindByUid, &$byEditLookup): void {
            $byFindByUid = $this->get(AddressEditRepository::class)->findByUid(2)?->getLine1();
            $byEditLookup = $this->get(AddressEditRepository::class)
                ->findByUidAndProfileUidIncludingHidden(2, 1)?->getLine1();
        });

        $this->assertNull($byFindByUid);
        $this->assertSame('Hidden two', $byEditLookup);
    }

    /**
     * The finders inherited from `Repository` are deliberately visible-only:
     * the edit repositories relax the enable fields per query in
     * `createEditQuery()` rather than through `setDefaultQuerySettings()`, so
     * reaching a hidden record requires calling a method that says so.
     */
    #[Test]
    public function inheritedFindersOfTheEditRepositoryStayVisibleOnly(): void
    {
        $editable = [];
        $this->executeInFrontendContext(function () use (&$editable): void {
            $editable = $this->sortedUids($this->get(ProfileEditRepository::class)->findAll());
        });

        $this->assertSame([1, 5, 6, 7], $editable);
    }

    /**
     * **`setEnableFieldsToBeIgnored(['disabled'])` is not redundant.**
     *
     * `setIgnoreEnableFields(true)` alone makes
     * `Typo3DbQueryParser::getFrontendConstraintStatement()` take its `else`
     * branch, which reduces the whole visibility constraint to `deleted = 0` —
     * dropping `starttime`, `endtime`, `fe_group` and the workspace
     * constraints along with `hidden`. With the second call in place the
     * constraint is built by `PageRepository::getDefaultConstraints()` with
     * `disabled` — and only `disabled` — excluded.
     *
     * Delete the second call from
     * {@see \SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\AbstractEditRepository::createEditQuery()}
     * and this test goes red: profile 3 (starts in 2038), profile 4 (expired in
     * 2001) and profile 8 (a record created in workspace 1) all appear in the
     * result. That is the point of the test, and the reason it asserts an exact
     * set rather than only the presence of the hidden record.
     *
     * The fixture dates it `2038-01-01` rather than something comfortably
     * distant, because `starttime` is a 32 bit integer column: PostgreSQL
     * rejects a larger value outright, while SQLite and MariaDB take it.
     */
    #[Test]
    public function editRepositoryStillHidesScheduledExpiredAndWorkspaceProfiles(): void
    {
        $editable = [];
        $this->executeInFrontendContext(function () use (&$editable): void {
            $editable = $this->sortedUids($this->get(ProfileEditRepository::class)->findAllByFrontendUser(1));
        });

        $this->assertNotContains(3, $editable, 'A profile whose starttime lies in the future must stay invisible.');
        $this->assertNotContains(4, $editable, 'A profile whose endtime has passed must stay invisible.');
        $this->assertNotContains(8, $editable, 'A profile created in a workspace must stay invisible.');
        $this->assertSame([1, 2, 7], $editable);
    }

    /**
     * The child tables carry the same enable columns, and the same reasoning —
     * see {@see editRepositoryStillHidesScheduledExpiredAndWorkspaceProfiles()}.
     */
    #[Test]
    public function editRepositoryStillHidesScheduledAndExpiredAddresses(): void
    {
        $editable = [];
        $this->executeInFrontendContext(function () use (&$editable): void {
            $editable = $this->uids($this->get(AddressEditRepository::class)->findAllByProfileUid(1));
        });

        $this->assertNotContains(4, $editable, 'An address whose starttime lies in the future must stay invisible.');
        $this->assertNotContains(5, $editable, 'An address whose endtime has passed must stay invisible.');
        $this->assertSame([1, 2, 3], $editable);
    }

    /**
     * Collects the uids of a result set, in the order the repository returned
     * them.
     *
     * @param iterable<DomainObjectInterface> $records
     * @return list<int>
     */
    private function uids(iterable $records): array
    {
        $uids = [];
        foreach ($records as $record) {
            $uids[] = (int)$record->getUid();
        }

        return $uids;
    }

    /**
     * The uids of a result set as a set, for the queries that carry no
     * ordering. Which order a DBMS returns unordered rows in is not defined,
     * so asserting one would make the test a database specific coin flip.
     *
     * @param iterable<DomainObjectInterface> $records
     * @return list<int>
     */
    private function sortedUids(iterable $records): array
    {
        $uids = $this->uids($records);
        sort($uids);

        return $uids;
    }
}
