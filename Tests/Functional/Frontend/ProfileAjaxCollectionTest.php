<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

/**
 * The three collection endpoints, and the two things Extbase does not do for
 * them.
 *
 * Both are silent when they are missing, which is why both are asserted against
 * the raw columns rather than against the response document:
 *
 * - **Reordering.** `ObjectStorage::attach()` on an already contained object
 *   updates an existing array key and leaves the iteration order alone, so a
 *   reorder that looks like it worked writes the old order. The values that
 *   have to end up in the `sorting` column are dense `1..n`, matching what
 *   `RelationHandler` writes for inline children in the backend.
 * - **Orphan removal.** Detaching a child writes `0` into the parent pointer
 *   and `0` into the sorting column and leaves the row otherwise untouched:
 *   `deleted = 0`, still occupying the reference index, invisible in the
 *   backend and never cleaned up. A test asserting only that the child is gone
 *   from the response passes over exactly that.
 */
final class ProfileAjaxCollectionTest extends AbstractProfileAjaxTestCase
{
    /**
     * A reordering writes the new positions as dense `1..n`.
     *
     * The stored sorting values are spread apart first, and the submitted order
     * moves **every** member of the collection. Both are deliberate: with the
     * fixture values `1..4` already dense, a test could not tell the written
     * positions apart from the values that were there anyway, and Extbase
     * writes a position only for a child whose position actually changed.
     */
    #[Test]
    public function reorderingWritesDensePositionsAndReadsBackInTheNewOrder(): void
    {
        $this->spreadSortingApart(self::ADDRESS_TABLE, self::OWNED_ADDRESS_UIDS);

        $response = $this->sendAjaxRequest(
            action: 'reorderChildren',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'address',
                'order' => [1, 4, 3, 2],
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1, 4, 3, 2], $this->childUidsOf($this->successData($response), 'addresses'));
        $this->assertSame(
            [1 => 1, 4 => 2, 3 => 3, 2 => 4],
            $this->sortingOf(self::ADDRESS_TABLE, [1, 4, 3, 2]),
            'The positions are written dense, starting at 1.',
        );
    }

    /**
     * The same for the second collection, so that neither is covered by the
     * argument that the other one is.
     */
    #[Test]
    public function reorderingTheEmailCollectionWritesDensePositions(): void
    {
        $this->spreadSortingApart(self::EMAIL_TABLE, self::OWNED_EMAIL_UIDS);

        $response = $this->sendAjaxRequest(
            action: 'reorderChildren',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'email',
                'order' => [1, 2],
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1, 2], $this->childUidsOf($this->successData($response), 'emails'));
        $this->assertSame([1 => 1, 2 => 2], $this->sortingOf(self::EMAIL_TABLE, [1, 2]));
    }

    /**
     * An order that is not a permutation of the whole collection is refused
     * before anything is touched.
     *
     * This is a security property rather than API pedantry: the intended set
     * replaces the collection wholesale, so a short list drops every record it
     * omits — and the persistence service then deletes them as orphans. A
     * client sending a stale collection would silently destroy data.
     *
     * @return \Generator<string, array{order: list<int>}>
     */
    public static function ordersThatAreNotPermutations(): \Generator
    {
        yield 'one member omitted' => ['order' => [2, 3, 1]];
        yield 'the hidden member omitted' => ['order' => [1, 2, 3]];
        yield 'a member listed twice' => ['order' => [1, 1, 3, 2]];
        yield 'an empty list' => ['order' => []];
        yield 'one member too many' => ['order' => [1, 2, 3, 4, 4]];
    }

    /**
     * @param list<int> $order
     */
    #[DataProvider('ordersThatAreNotPermutations')]
    #[Test]
    public function anOrderThatIsNotAPermutationIsRefusedAndDeletesNothing(array $order): void
    {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendAjaxRequest(
            action: 'reorderChildren',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'address',
                'order' => $order,
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([1786495923], $this->errorCodes($response));
        $this->assertSame($snapshot, $this->recordSnapshot(), 'Not a single record was deleted or moved.');
    }

    /**
     * Removing a child deletes its row rather than detaching it.
     *
     * `deleted = 1` is the assertion that matters. A row left behind with
     * `profile = 0`, `sorting = 0` and `deleted = 0` is what Extbase produces on
     * its own, it is invisible in every interface, and nothing will ever clean
     * it up — which is why the persistence service removes the difference
     * between the intended and the owned set explicitly.
     */
    #[Test]
    public function removingAChildDeletesItsRowAndLeavesNoOrphan(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'removeChild',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'address',
                'childUid' => 1,
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([2, 3, 4], $this->childUidsOf($this->successData($response), 'addresses'));

        $removed = $this->rawRow(self::ADDRESS_TABLE, 1);
        $this->assertSame(1, (int)$removed['deleted'], 'The removed row is deleted, not merely detached.');
        $this->assertSame(0, $this->orphanCount(self::ADDRESS_TABLE), 'No undeleted row lost its parent pointer.');

        $this->assertSame(
            [2 => 1, 3 => 2, 4 => 3],
            $this->sortingOf(self::ADDRESS_TABLE, [2, 3, 4]),
            'The remaining children keep a dense sorting.',
        );
    }

    /**
     * The same for the second collection.
     */
    #[Test]
    public function removingAnEmailDeletesItsRowAndLeavesNoOrphan(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'removeChild',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'email',
                'childUid' => 2,
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1], $this->childUidsOf($this->successData($response), 'emails'));

        $this->assertSame(1, (int)$this->rawRow(self::EMAIL_TABLE, 2)['deleted']);
        $this->assertSame(0, $this->orphanCount(self::EMAIL_TABLE));
    }

    /**
     * A new child is appended, stored on the page of its parent, and visible.
     */
    #[Test]
    public function addingAChildAppendsItToTheCollection(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'addChild',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'address',
                'data' => ['type' => 'work', 'line1' => 'Analytical Engine Lane 4', 'line2' => 'Top floor'],
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());

        $uids = $this->childUidsOf($this->successData($response), 'addresses');
        $this->assertCount(count(self::OWNED_ADDRESS_UIDS) + 1, $uids);

        $newUid = $uids[count($uids) - 1];
        $this->assertNotContains($newUid, self::OWNED_ADDRESS_UIDS, 'The appended record is the new one.');

        $row = $this->rawRow(self::ADDRESS_TABLE, $newUid);
        $this->assertSame('work', $row['type']);
        $this->assertSame('Analytical Engine Lane 4', $row['line1']);
        $this->assertSame('Top floor', $row['line2']);
        $this->assertSame(self::OWNED_PROFILE_UID, (int)$row['profile']);
        $this->assertSame(self::STORAGE_PAGE_ID, (int)$row['pid']);
        $this->assertSame(0, (int)$row['hidden']);
        $this->assertSame(count(self::OWNED_ADDRESS_UIDS) + 1, (int)$row['sorting'], 'It sorts last.');
    }

    /**
     * A child the payload cannot describe is refused by the rule set, and the
     * collection is left alone.
     */
    #[Test]
    public function addingAChildWithARejectedValueWritesNothing(): void
    {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendAjaxRequest(
            action: 'addChild',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'email',
                'data' => ['type' => 'private', 'email' => 'not-an-email-address'],
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame($snapshot, $this->recordSnapshot());
    }

    /**
     * The visibility endpoint is the only path to the `hidden` column, and it
     * is idempotent rather than a toggle.
     *
     * @return \Generator<string, array{hidden: bool, childUid: int, expected: int}>
     */
    public static function visibilityChanges(): \Generator
    {
        yield 'hiding a visible address' => ['hidden' => true, 'childUid' => 1, 'expected' => 1];
        yield 'publishing a hidden address' => ['hidden' => false, 'childUid' => 4, 'expected' => 0];
        yield 'hiding an already hidden address' => ['hidden' => true, 'childUid' => 4, 'expected' => 1];
        yield 'publishing an already visible address' => ['hidden' => false, 'childUid' => 1, 'expected' => 0];
    }

    #[DataProvider('visibilityChanges')]
    #[Test]
    public function theVisibilityEndpointWritesTheSubmittedState(bool $hidden, int $childUid, int $expected): void
    {
        $response = $this->sendAjaxRequest(
            action: 'setChildVisibility',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'address',
                'childUid' => $childUid,
                'hidden' => $hidden,
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, (int)$this->rawRow(self::ADDRESS_TABLE, $childUid)['hidden']);
        $this->assertSame(
            self::OWNED_ADDRESS_UIDS,
            $this->childUidsOf($this->successData($response), 'addresses'),
            'The collection is answered in full, hidden records included, and its order is unchanged.',
        );
    }

    /**
     * Spreads the stored sorting values of a collection apart, keeping the
     * order they are in.
     *
     * @param list<int> $uidsInStoredOrder
     */
    private function spreadSortingApart(string $table, array $uidsInStoredOrder): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable($table);
        $position = 0;
        foreach ($uidsInStoredOrder as $uid) {
            $position += 10;
            $connection->update($table, ['sorting' => $position], ['uid' => $uid]);
        }
    }

    /**
     * The number of undeleted rows that have no parent — the shape a detach
     * without a delete leaves behind.
     */
    private function orphanCount(string $table): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('profile', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
