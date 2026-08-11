<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Domain;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Manual sorting of a child collection, written through the Extbase
 * persistence layer.
 *
 * Sorting is the part of this feature that fails most quietly. Extbase writes
 * the sorting column in exactly one place — `Backend::attachObjectToParentObject()`,
 * from `childSortByFieldName`, which is fed by `foreign_sortby` on the
 * **parent** column and by nothing else. `ctrl.sortby` on the child table is
 * invisible to the write path. And `ObjectStorage` has no reorder API at all:
 * it is an array keyed by `spl_object_hash()`, so `attach()` on an object that
 * is already contained rewrites the existing key in place, which leaves the
 * iteration order untouched while marking the storage dirty. The reorder then
 * looks like it worked and produces the old order in the database.
 *
 * Both facts are covered here, the second one deliberately as a test of the
 * wrong implementation: {@see attachingAnAlreadyContainedAddressDoesNotReorderTheCollection()}
 * asserts that the naive reorder does *nothing*, so the day somebody replaces
 * the detach-all recipe with it, the round trip test next to it goes red and
 * this one explains why.
 */
final class CollectionSortingTest extends AbstractProfileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/Profiles.csv');
    }

    /**
     * Reordering by detach-all plus re-attach in the target order persists
     * dense `1..n` sorting values, and reading the collection back yields the
     * new order.
     *
     * Detaching every member resets the internal position counter to `0`
     * (`ObjectStorage::offsetUnset()`), which is what makes the re-attached
     * positions line up with the `$sortingPosition` counter in
     * `Backend::persistObjectStorage()`.
     *
     * Remove `foreign_sortby` from the `addresses` column of the profile TCA
     * and this test goes red: `childSortByFieldName` is then `null` and no
     * sorting value is written at all, so the rows keep the order they had.
     */
    #[Test]
    public function reorderingAddressesPersistsDenseSortingValuesAndIsReadBackInTheNewOrder(): void
    {
        $reorderedLines = [];
        $this->executeInFrontendContext(function () use (&$reorderedLines): void {
            $profileRepository = $this->get(ProfileRepository::class);
            $persistenceManager = $this->get(PersistenceManagerInterface::class);

            $profile = $profileRepository->findByUid(7);
            $this->assertInstanceOf(Profile::class, $profile);
            // The fixture stores sorting values 3, 1, 2 for the addresses 11,
            // 12 and 13, so the collection does not arrive in uid order.
            $this->assertSame(['Beta', 'Gamma', 'Alpha'], $this->lines($profile));

            $this->reorderAddresses($profile, [13, 11, 12]);

            $profileRepository->update($profile);
            $persistenceManager->persistAll();

            // Forget every object of this request, so the collection below is
            // read from the database rather than handed back from the identity
            // map — which would assert the state of the objects in memory and
            // nothing about what was written.
            $persistenceManager->clearState();

            $reloaded = $profileRepository->findByUid(7);
            $this->assertInstanceOf(Profile::class, $reloaded);
            $reorderedLines = $this->lines($reloaded);
        });

        $this->assertSame(['Gamma', 'Alpha', 'Beta'], $reorderedLines);

        $sorting = $this->readIntColumnByUid(self::ADDRESS_TABLE, 'sorting');
        $this->assertSame(2, $sorting[11]);
        $this->assertSame(3, $sorting[12]);
        $this->assertSame(1, $sorting[13]);

        // The addresses of the other profile are not members of the storage
        // that was written, so nothing may have touched them.
        $this->assertSame([1, 2, 3, 4, 5], [$sorting[1], $sorting[2], $sorting[3], $sorting[4], $sorting[5]]);
    }

    /**
     * The trap: `attach()` on an object that is already contained does not
     * reorder anything.
     *
     * `ObjectStorage::offsetSet()` writes `$this->storage[spl_object_hash($object)]`,
     * and writing an existing key of a PHP array updates the value in place —
     * the element keeps its position. The storage is marked dirty and its
     * position counter is bumped, so the write path *does* run, and it writes
     * the sorting values of the **old** order.
     *
     * This test asserts exactly that, so it documents the behaviour rather than
     * the wish. It is the reason
     * {@see reorderingAddressesPersistsDenseSortingValuesAndIsReadBackInTheNewOrder()}
     * empties the storage first.
     */
    #[Test]
    public function attachingAnAlreadyContainedAddressDoesNotReorderTheCollection(): void
    {
        $linesAfterNaiveReorder = [];
        $this->executeInFrontendContext(function () use (&$linesAfterNaiveReorder): void {
            $profileRepository = $this->get(ProfileRepository::class);
            $persistenceManager = $this->get(PersistenceManagerInterface::class);

            $profile = $profileRepository->findByUid(7);
            $this->assertInstanceOf(Profile::class, $profile);

            $storage = $profile->getAddresses();
            $byUid = $this->addressesByUid($profile);
            // The naive reorder: re-attach in the target order without
            // detaching first.
            foreach ([13, 11, 12] as $uid) {
                $storage->attach($byUid[$uid]);
            }

            $this->assertTrue($storage->_isDirty(), 'attach() marks the storage dirty, so the write path does run.');

            $profileRepository->update($profile);
            $persistenceManager->persistAll();
            $persistenceManager->clearState();

            $reloaded = $profileRepository->findByUid(7);
            $this->assertInstanceOf(Profile::class, $reloaded);
            $linesAfterNaiveReorder = $this->lines($reloaded);
        });

        $this->assertSame(['Beta', 'Gamma', 'Alpha'], $linesAfterNaiveReorder);

        $sorting = $this->readIntColumnByUid(self::ADDRESS_TABLE, 'sorting');
        $this->assertSame([3, 1, 2], [$sorting[11], $sorting[12], $sorting[13]]);
    }

    /**
     * A member taken out of the collection keeps its row.
     *
     * Extbase writes `0` into the parent pointer and into the sorting column
     * and leaves everything else alone — `deleted` stays `0`, `hidden` is
     * untouched, the `pid` is unchanged. Deleting the row is the edit service's
     * job, and this test pins the behaviour that makes that necessary: without
     * it, the orphan is a row nobody sees and nobody cleans up.
     */
    #[Test]
    public function detachingAnAddressOrphansTheRowInsteadOfDeletingIt(): void
    {
        $this->executeInFrontendContext(function (): void {
            $profileRepository = $this->get(ProfileRepository::class);
            $persistenceManager = $this->get(PersistenceManagerInterface::class);

            $profile = $profileRepository->findByUid(7);
            $this->assertInstanceOf(Profile::class, $profile);

            $byUid = $this->addressesByUid($profile);
            $profile->removeAddress($byUid[12]);

            $profileRepository->update($profile);
            $persistenceManager->persistAll();
        });

        $this->assertSame(0, $this->readIntColumnByUid(self::ADDRESS_TABLE, 'profile')[12]);
        $this->assertSame(0, $this->readIntColumnByUid(self::ADDRESS_TABLE, 'sorting')[12]);
        $this->assertSame(0, $this->readIntColumnByUid(self::ADDRESS_TABLE, 'deleted')[12]);
        $this->assertSame(0, $this->readIntColumnByUid(self::ADDRESS_TABLE, 'hidden')[12]);
        // The remaining members keep the sorting values they had, gap
        // included: `persistObjectStorage()` only writes a member whose
        // position in the storage differs from the position in the clean
        // clone, and detaching one member does not move the others. A
        // dense `1..n` numbering is a property of the reorder recipe, not
        // of a removal — see
        // {@see reorderingAddressesPersistsDenseSortingValuesAndIsReadBackInTheNewOrder()}.
        $sorting = $this->readIntColumnByUid(self::ADDRESS_TABLE, 'sorting');
        $this->assertSame([3, 2], [$sorting[11], $sorting[13]]);
    }

    /**
     * Empties the collection and refills it in the given order, which is the
     * only way to reorder an `ObjectStorage`.
     *
     * `toArray()` is iterated rather than the storage itself: detaching while
     * iterating the live storage mutates what is being iterated.
     *
     * @param list<int> $orderedUids
     */
    private function reorderAddresses(Profile $profile, array $orderedUids): void
    {
        $storage = $profile->getAddresses();
        $byUid = $this->addressesByUid($profile);

        foreach ($storage->toArray() as $address) {
            $storage->detach($address);
        }

        foreach ($orderedUids as $uid) {
            $this->assertArrayHasKey($uid, $byUid, 'A uid that is not a member is a caller error, not a missing key.');
            $storage->attach($byUid[$uid]);
        }
    }

    /**
     * @return array<int, Address>
     */
    private function addressesByUid(Profile $profile): array
    {
        $byUid = [];
        foreach ($profile->getAddresses() as $address) {
            $byUid[(int)$address->getUid()] = $address;
        }

        return $byUid;
    }

    /**
     * @return list<string>
     */
    private function lines(Profile $profile): array
    {
        $lines = [];
        foreach ($profile->getAddresses() as $address) {
            $lines[] = $address->getLine1();
        }

        return $lines;
    }
}
