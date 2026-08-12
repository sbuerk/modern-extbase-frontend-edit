<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Domain\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\AddressDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The address half of the mapping layer, and the class that documents the
 * collection contract both child mappers implement.
 *
 * The mapper does not persist, so none of this needs a database: the tests
 * assert what lands on the model and what the returned set contains, which is
 * the whole of the mapper's responsibility.
 */
final class AddressDataMapperTest extends UnitTestCase
{
    private AddressDataMapper $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new AddressDataMapper();
    }

    #[Test]
    public function mapWritesEveryWritableProperty(): void
    {
        $address = new Address();

        $this->subject->map(
            new AddressData(type: 'work', line1: 'Example Street 1', line2: '12345 Example City'),
            $address,
        );

        $this->assertSame('work', $address->getType());
        $this->assertSame('Example Street 1', $address->getLine1());
        $this->assertSame('12345 Example City', $address->getLine2());
    }

    #[Test]
    public function applyPropertyLeavesEveryOtherPropertyUntouched(): void
    {
        $address = new Address();
        $address->setType('work');
        $address->setLine1('Example Street 1');
        $address->setLine2('12345 Example City');

        $this->subject->applyProperty($address, 'line1', 'Other Street 2');

        $this->assertSame('Other Street 2', $address->getLine1());
        $this->assertSame('work', $address->getType());
        $this->assertSame('12345 Example City', $address->getLine2());
    }

    /**
     * The dispatch of `applyProperty()` is a closed `switch`, so a name that is
     * not a writable property has nowhere to go but `default`. That is what
     * makes a payload controlled `pid` structurally impossible rather than
     * merely unimplemented.
     */
    #[Test]
    #[DataProvider('propertyNamesThatAreNotWritable')]
    public function applyPropertyRejectsAPropertyItCannotWrite(string $propertyName): void
    {
        $address = new Address();
        $address->setPid(12);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492111);

        try {
            $this->subject->applyProperty($address, $propertyName, 5);
        } finally {
            $this->assertSame(12, $address->getPid());
            $this->assertNull($address->getUid());
            $this->assertFalse($address->isHidden());
        }
    }

    /**
     * @return \Generator<string, array{propertyName: string}>
     */
    public static function propertyNamesThatAreNotWritable(): \Generator
    {
        yield 'storage location: pid' => ['propertyName' => 'pid'];
        yield 'record identity: uid' => ['propertyName' => 'uid'];
        yield 'publication state: hidden' => ['propertyName' => 'hidden'];
        yield 'language: sys_language_uid' => ['propertyName' => 'sys_language_uid'];
        yield 'invented by a client' => ['propertyName' => 'somethingElse'];
    }

    /**
     * Validation has already run when a value reaches the mapper, so a wrong
     * type at this point is a bug rather than a user error — coercing it would
     * turn `42` or `['x']` into stored data.
     */
    #[Test]
    #[DataProvider('valuesThatAreNotAString')]
    public function applyPropertyRejectsANonStringValue(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492112);

        $this->subject->applyProperty(new Address(), 'line1', $value);
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function valuesThatAreNotAString(): \Generator
    {
        yield 'integer' => ['value' => 42];
        yield 'null' => ['value' => null];
        yield 'array' => ['value' => ['x']];
        yield 'boolean' => ['value' => true];
    }

    /**
     * A positive integer key addresses an existing child, which is looked up in
     * the owner constrained set the caller resolved. The instance is reused
     * rather than replaced, because an Extbase entity has an identity and the
     * persistence session keys on the instance.
     */
    #[Test]
    public function anExistingChildIsUpdatedInPlace(): void
    {
        $existing = $this->persistedAddress(5, 'home', 'Old Street 1');

        $intended = $this->subject->mapCollection(
            $this->profileOnPage(12),
            [5 => new AddressData(type: 'work', line1: 'New Street 2')],
            [$existing],
        );

        $this->assertSame([$existing], $intended->toArray());
        $this->assertSame('work', $existing->getType());
        $this->assertSame('New Street 2', $existing->getLine1());
    }

    /**
     * Any key that is not a positive integer creates a new child. The key is
     * used for nothing but that distinction and never reaches the model.
     */
    #[Test]
    #[DataProvider('keysThatCreateANewChild')]
    public function aKeyThatIsNotAPositiveIntegerCreatesANewChild(int|string $key): void
    {
        $existing = $this->persistedAddress(5, 'home', 'Old Street 1');

        $intended = $this->subject->mapCollection(
            $this->profileOnPage(12),
            [$key => new AddressData(type: 'work', line1: 'New Street 2')],
            [$existing],
        );

        $created = $intended->toArray();
        $this->assertCount(1, $created);
        $this->assertNotSame($existing, $created[0]);
        $this->assertNull($created[0]->getUid());
        $this->assertSame('New Street 2', $created[0]->getLine1());
        $this->assertSame('Old Street 1', $existing->getLine1());
    }

    /**
     * @return \Generator<string, array{key: int|string}>
     */
    public static function keysThatCreateANewChild(): \Generator
    {
        yield 'client side identifier' => ['key' => 'new-1'];
        yield 'zero' => ['key' => 0];
        yield 'negative integer' => ['key' => -1];
        // A JSON object key is a string, and PHP turns a numeric one into an
        // int on decode — but "new" prefixed or not, a non-numeric string stays
        // a string and must not be looked up.
        yield 'numeric looking string that PHP does not cast' => ['key' => '007'];
    }

    /**
     * A new child gets the pid of the already resolved, owner constrained
     * parent record. That is required rather than tidy:
     * `Backend::determineStoragePageIdForNewRecord()` prefers the object's own
     * pid over every configuration source, so a child without one lands
     * wherever `persistence.storagePid` happens to point.
     */
    #[Test]
    public function aNewChildIsPlacedOnThePageOfItsParent(): void
    {
        $intended = $this->subject->mapCollection(
            $this->profileOnPage(12),
            ['new-1' => new AddressData(line1: 'New Street 2')],
            [],
        );

        $created = $intended->toArray();
        $this->assertCount(1, $created);
        $this->assertSame(12, $created[0]->getPid());
    }

    /**
     * `$existing` is the owner constrained set the caller resolved, so a key
     * that is not in it is either another user's record or one that does not
     * exist. `docs/frontend-edit/authorization.md` requires both to produce the
     * same answer, and neither may be silently skipped.
     */
    #[Test]
    public function aKeyOutsideTheAddressableSetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492113);

        $this->subject->mapCollection(
            $this->profileOnPage(12),
            [99 => new AddressData(line1: 'New Street 2')],
            [$this->persistedAddress(5, 'home', 'Old Street 1')],
        );
    }

    /**
     * An unpersisted entity has no uid and is therefore unaddressable by a
     * client: a payload can only ever reach it by creating a new record.
     */
    #[Test]
    public function anUnpersistedExistingChildIsNotAddressable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492113);

        $this->subject->mapCollection(
            $this->profileOnPage(12),
            [5 => new AddressData(line1: 'New Street 2')],
            [new Address()],
        );
    }

    /**
     * Orphan removal is the write path's business. A child that was not
     * submitted is neither returned nor touched here — a detach alone would
     * leave the row behind with a cleared parent pointer and `sorting = 0`.
     */
    #[Test]
    public function anExistingChildThatWasNotSubmittedIsNeitherReturnedNorMutated(): void
    {
        $submittedChild = $this->persistedAddress(5, 'home', 'Old Street 1');
        $untouchedChild = $this->persistedAddress(6, 'work', 'Other Street 2');

        $intended = $this->subject->mapCollection(
            $this->profileOnPage(12),
            [5 => new AddressData(type: 'work', line1: 'New Street 3')],
            [$submittedChild, $untouchedChild],
        );

        $this->assertSame([$submittedChild], $intended->toArray());
        $this->assertSame('work', $untouchedChild->getType());
        $this->assertSame('Other Street 2', $untouchedChild->getLine1());
        $this->assertSame(6, $untouchedChild->getUid());
    }

    /**
     * The returned set is "the intended set, in the intended order", so the
     * payload order is what comes back — not the order of `$existing`.
     */
    #[Test]
    public function theReturnedSetKeepsTheOrderOfThePayload(): void
    {
        $first = $this->persistedAddress(5, 'home', 'Old Street 1');
        $second = $this->persistedAddress(6, 'work', 'Other Street 2');

        $intended = $this->subject->mapCollection(
            $this->profileOnPage(12),
            [
                6 => new AddressData(line1: 'Second'),
                'new-1' => new AddressData(line1: 'Third'),
                5 => new AddressData(line1: 'First'),
            ],
            [$first, $second],
        );

        $this->assertSame(
            ['Second', 'Third', 'First'],
            array_map(static fn(Address $address): string => $address->getLine1(), $intended->toArray()),
        );
    }

    /**
     * @param int<0, max> $pid
     */
    private function profileOnPage(int $pid): Profile
    {
        $profile = new Profile();
        $profile->setPid($pid);

        return $profile;
    }

    private function persistedAddress(int $uid, string $type, string $line1): Address
    {
        $address = new Address();
        $address->_setProperty('uid', $uid);
        $address->setType($type);
        $address->setLine1($line1);

        return $address;
    }
}
