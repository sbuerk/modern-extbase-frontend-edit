<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\AddressEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\EmailEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\ProfileEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;

/**
 * Hidden children are not reachable through the aggregate.
 *
 * This is the behaviour the whole child edit repository layer is built around,
 * and it is invisible in any test whose fixtures contain only visible children.
 * `DataMapper::getPreparedQuery()` builds a **new** query through
 * `QueryFactory::create()` for every relation and assigns nothing but
 * `respectStoragePage` and `respectSysLanguage` to it, so `ignoreEnableFields`
 * keeps the context default — `false` in the frontend. The query settings of
 * the query that loaded the parent therefore never reach its children, and the
 * class docblock of `Typo3QuerySettings` says so in prose.
 *
 * The consequence is the rule the edit flow rests on: **children are loaded
 * through their own edit repositories and assembled by the edit service; the
 * form is never built from the parent's collection.** Both tests below assert
 * the two halves of that in one place, so the "cleanup" that replaces the
 * repository call with `$profile->getAddresses()` cannot be made without
 * turning one of them red.
 */
final class AggregateChildrenTest extends AbstractProfileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/Profiles.csv');
    }

    /**
     * Note which repository loads the parent here: the **edit** one, whose
     * query settings do see hidden records. The hidden address is missing from
     * the collection anyway, which is the point — how the parent was fetched
     * makes no difference at all.
     */
    #[Test]
    public function hiddenAddressIsAbsentFromTheAggregateAndPresentOnTheEditRepository(): void
    {
        $throughAggregate = [];
        $throughEditRepository = [];
        $this->executeInFrontendContext(function () use (&$throughAggregate, &$throughEditRepository): void {
            $profile = $this->get(ProfileEditRepository::class)->findByUidIncludingHidden(1);
            $this->assertInstanceOf(Profile::class, $profile);

            $throughAggregate = $this->uids($profile->getAddresses());
            $throughEditRepository = $this->uids($this->get(AddressEditRepository::class)->findAllByProfileUid(1));
        });

        $this->assertSame([1, 3], $throughAggregate);
        $this->assertSame([1, 2, 3], $throughEditRepository);
    }

    #[Test]
    public function hiddenEmailIsAbsentFromTheAggregateAndPresentOnTheEditRepository(): void
    {
        $throughAggregate = [];
        $throughEditRepository = [];
        $this->executeInFrontendContext(function () use (&$throughAggregate, &$throughEditRepository): void {
            $profile = $this->get(ProfileEditRepository::class)->findByUidIncludingHidden(1);
            $this->assertInstanceOf(Profile::class, $profile);

            $throughAggregate = $this->uids($profile->getEmails());
            $throughEditRepository = $this->uids($this->get(EmailEditRepository::class)->findAllByProfileUid(1));
        });

        $this->assertSame([1], $throughAggregate);
        $this->assertSame([1, 2], $throughEditRepository);
    }

    /**
     * The display side reaches the same collection, and must reach exactly the
     * visible part of it. This is the half that would break unnoticed if the
     * relation ever gained hidden-inclusive query settings.
     */
    #[Test]
    public function aggregateOfADisplayedProfileContainsTheVisibleChildrenOnly(): void
    {
        $addresses = [];
        $emails = [];
        $this->executeInFrontendContext(function () use (&$addresses, &$emails): void {
            $profile = $this->get(ProfileRepository::class)->findByUid(1);
            $this->assertInstanceOf(Profile::class, $profile);

            $addresses = $this->uids($profile->getAddresses());
            $emails = $this->uids($profile->getEmails());
        });

        // 2 is hidden, 4 has not started yet, 5 has expired.
        $this->assertSame([1, 3], $addresses);
        $this->assertSame([1], $emails);
    }

    /**
     * The collection arrives in the manual sorting order, which comes from
     * `foreign_sortby` on the parent column: `ColumnMapFactory` reads it into
     * `childSortByFieldName`, and `DataMapper::getOrderingsForColumnMap()`
     * turns that into the ordering of the relation query.
     */
    #[Test]
    public function aggregateIsOrderedByTheManualSortingOfTheChildren(): void
    {
        $lines = [];
        $this->executeInFrontendContext(function () use (&$lines): void {
            $profile = $this->get(ProfileRepository::class)->findByUid(7);
            $this->assertInstanceOf(Profile::class, $profile);

            foreach ($profile->getAddresses() as $address) {
                $lines[] = $address->getLine1();
            }
        });

        // The fixture stores the three addresses with sorting values 3, 1, 2,
        // so uid order and sorting order differ — otherwise the assertion would
        // hold for a query with no ordering at all.
        $this->assertSame(['Beta', 'Gamma', 'Alpha'], $lines);
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

        return $uids;
    }
}
