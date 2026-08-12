<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Domain\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\AddressDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\EmailDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\ProfileDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The aggregate root half of the mapping layer.
 *
 * The child mappers are the real ones rather than test doubles: they carry no
 * dependencies and no state, so a double would only assert that a delegation
 * happened while the real object additionally proves what it produced.
 */
final class ProfileDataMapperTest extends UnitTestCase
{
    private ProfileDataMapper $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ProfileDataMapper(new AddressDataMapper(), new EmailDataMapper());
    }

    #[Test]
    public function mapWritesEveryWritableProperty(): void
    {
        $profile = new Profile();

        $this->subject->map(
            new ProfileData(
                shortname: 'jdoe',
                firstname: 'John',
                lastname: 'Doe',
                birthday: '1980-05-17',
                bio: 'Hello.',
            ),
            $profile,
        );

        $this->assertSame('jdoe', $profile->getShortname());
        $this->assertSame('John', $profile->getFirstname());
        $this->assertSame('Doe', $profile->getLastname());
        $this->assertSame('1980-05-17 00:00:00', $profile->getBirthday()?->format('Y-m-d H:i:s'));
        $this->assertSame('Hello.', $profile->getBio());
    }

    /**
     * The full path is a loop over the partial one, so per property behaviour
     * exists once. This asserts the other direction of that: a single property
     * write leaves the rest of the record alone.
     */
    #[Test]
    public function applyPropertyLeavesEveryOtherPropertyUntouched(): void
    {
        $profile = new Profile();
        $this->subject->map(
            new ProfileData(shortname: 'jdoe', firstname: 'John', lastname: 'Doe', birthday: '1980-05-17', bio: 'Hello.'),
            $profile,
        );

        $this->subject->applyProperty($profile, 'firstname', 'Jane');

        $this->assertSame('Jane', $profile->getFirstname());
        $this->assertSame('jdoe', $profile->getShortname());
        $this->assertSame('Doe', $profile->getLastname());
        $this->assertSame('1980-05-17', $profile->getBirthday()?->format('Y-m-d'));
        $this->assertSame('Hello.', $profile->getBio());
    }

    /**
     * The last layer that could let a payload controlled `pid` through, closed
     * rather than guarded: the dispatch is a `switch` over
     * `WRITABLE_PROPERTIES` and everything else lands in `default`.
     *
     * `feUser` and `hidden` are in the same set on purpose — ownership is
     * resolved from the session and publishing is a dedicated action with its
     * own ownership assertion, so neither is a value a payload may assign.
     */
    #[Test]
    #[DataProvider('propertyNamesThatAreNotWritable')]
    public function applyPropertyRejectsAPropertyItCannotWrite(string $propertyName): void
    {
        $profile = new Profile();
        $profile->setPid(12);
        $profile->setFeUser(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492101);

        try {
            $this->subject->applyProperty($profile, $propertyName, 5);
        } finally {
            $this->assertSame(12, $profile->getPid());
            $this->assertNull($profile->getUid());
            $this->assertSame(3, $profile->getFeUser());
            $this->assertFalse($profile->isHidden());
        }
    }

    /**
     * @return \Generator<string, array{propertyName: string}>
     */
    public static function propertyNamesThatAreNotWritable(): \Generator
    {
        yield 'storage location: pid' => ['propertyName' => 'pid'];
        yield 'record identity: uid' => ['propertyName' => 'uid'];
        yield 'ownership: feUser' => ['propertyName' => 'feUser'];
        yield 'publication state: hidden' => ['propertyName' => 'hidden'];
        yield 'language: sys_language_uid' => ['propertyName' => 'sys_language_uid'];
        yield 'file reference, written by the upload path' => ['propertyName' => 'image'];
        yield 'invented by a client' => ['propertyName' => 'somethingElse'];
    }

    /**
     * A full submit cannot reach `feUser`, `hidden` or the child collections
     * either: `map()` iterates `WRITABLE_PROPERTIES` and reads the payload
     * through an explicit `match`, so a property the DTO grows later stays
     * invisible until someone adds the arm.
     */
    #[Test]
    public function mapTouchesNothingOutsideTheWritableProperties(): void
    {
        $profile = new Profile();
        $profile->setPid(12);
        $profile->setFeUser(3);
        $profile->setHidden(true);
        $profile->addAddress(new Address());

        $this->subject->map(new ProfileData(shortname: 'jdoe'), $profile);

        $this->assertSame(12, $profile->getPid());
        $this->assertNull($profile->getUid());
        $this->assertSame(3, $profile->getFeUser());
        $this->assertTrue($profile->isHidden());
        $this->assertCount(1, $profile->getAddresses());
        $this->assertNull($profile->getImage());
    }

    #[Test]
    #[DataProvider('valuesThatAreNotAString')]
    public function applyPropertyRejectsANonStringValue(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492103);

        $this->subject->applyProperty(new Profile(), 'shortname', $value);
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
     * The `bio` column is a nullable `longtext`, so MySQL rejects a literal
     * default on it and the `''` invariant can only be enforced in PHP. A
     * cleared textarea arrives as `null` or as `''` depending on how the client
     * serializes it, and both have to end up as `''` — `null` would be a
     * `TypeError` on `setBio()`, and a nullable property would move the
     * invariant out of the model.
     */
    #[Test]
    #[DataProvider('clearedTextValues')]
    public function aClearedTextPropertyBecomesTheEmptyString(mixed $value): void
    {
        $profile = new Profile();
        $profile->setBio('Hello.');

        $this->subject->applyProperty($profile, 'bio', $value);

        $this->assertSame('', $profile->getBio());
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function clearedTextValues(): \Generator
    {
        yield 'serialized as null' => ['value' => null];
        yield 'serialized as an empty string' => ['value' => ''];
    }

    /**
     * `null` is the one value `bio` absorbs. Everything else that is not a
     * string is still a programming error and still throws.
     */
    #[Test]
    public function aNonStringTextValueIsStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492103);

        $this->subject->applyProperty(new Profile(), 'bio', ['x']);
    }

    /**
     * The mapper parses with `ProfileData::BIRTHDAY_FORMAT` rather than a
     * literal, so the wire format cannot drift away from the one the rule set
     * validates and the DTO parses.
     */
    #[Test]
    public function aBirthdayIsParsedFromThePinnedWireFormat(): void
    {
        $profile = new Profile();

        $this->subject->applyProperty(
            $profile,
            'birthday',
            (new \DateTimeImmutable('1980-05-17'))->format(ProfileData::BIRTHDAY_FORMAT),
        );

        $this->assertSame('1980-05-17 00:00:00', $profile->getBirthday()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    #[DataProvider('clearedBirthdayValues')]
    public function aClearedBirthdayBecomesNull(mixed $value): void
    {
        $profile = new Profile();
        $profile->setBirthday(new \DateTimeImmutable('1980-05-17'));

        $this->subject->applyProperty($profile, 'birthday', $value);

        $this->assertNull($profile->getBirthday());
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function clearedBirthdayValues(): \Generator
    {
        yield 'serialized as null' => ['value' => null];
        yield 'serialized as an empty string' => ['value' => ''];
    }

    /**
     * Turning an unparseable date into "no birthday" would store a value the
     * user did not enter. The validation layer rejects such a value before
     * anything reaches here, so arriving with one is a bug.
     */
    #[Test]
    #[DataProvider('valuesThatAreNotAWireFormatDate')]
    public function anUnparseableBirthdayIsRejectedRatherThanCleared(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492102);

        $this->subject->applyProperty(new Profile(), 'birthday', $value);
    }

    /**
     * @return \Generator<string, array{value: string}>
     */
    public static function valuesThatAreNotAWireFormatDate(): \Generator
    {
        yield 'not a date at all' => ['value' => 'yesterday'];
        yield 'ATOM datetime' => ['value' => '1980-05-17T13:45:00+02:00'];
        yield 'German notation' => ['value' => '17.05.1980'];
    }

    /**
     * A caller that already holds a date object is accepted; `\DateTime` is
     * converted rather than refused, because the conversion is exact.
     */
    #[Test]
    public function anAlreadyParsedBirthdayIsNormalizedRatherThanReparsed(): void
    {
        $profile = new Profile();
        $immutable = new \DateTimeImmutable('1980-05-17 00:00:00');

        $this->subject->applyProperty($profile, 'birthday', $immutable);
        $this->assertSame($immutable, $profile->getBirthday());

        $this->subject->applyProperty($profile, 'birthday', new \DateTime('1815-12-10 00:00:00'));
        $this->assertSame('1815-12-10 00:00:00', $profile->getBirthday()?->format('Y-m-d H:i:s'));
    }

    /**
     * The write path injects one mapper for the whole aggregate rather than
     * three, so the child collections are reachable from here.
     */
    #[Test]
    public function addressesAreMappedThroughTheAggregateMapper(): void
    {
        $profile = new Profile();
        $profile->setPid(12);
        $existing = new Address();
        $existing->_setProperty('uid', 5);

        $intended = $this->subject->mapAddresses(
            $profile,
            [
                5 => new AddressData(type: 'work', line1: 'Example Street 1'),
                'new-1' => new AddressData(type: 'home', line1: 'Other Street 2'),
            ],
            [$existing],
        );

        $addresses = $intended->toArray();
        $this->assertCount(2, $addresses);
        $this->assertSame($existing, $addresses[0]);
        $this->assertSame('Example Street 1', $addresses[0]->getLine1());
        $this->assertNull($addresses[1]->getUid());
        $this->assertSame(12, $addresses[1]->getPid());
        $this->assertSame('Other Street 2', $addresses[1]->getLine1());
    }

    #[Test]
    public function emailsAreMappedThroughTheAggregateMapper(): void
    {
        $profile = new Profile();
        $profile->setPid(12);
        $existing = new Email();
        $existing->_setProperty('uid', 5);

        $intended = $this->subject->mapEmails(
            $profile,
            [
                5 => new EmailData(type: 'business', email: 'john.doe@example.com'),
                'new-1' => new EmailData(type: 'private', email: 'jane.doe@example.com'),
            ],
            [$existing],
        );

        $emails = $intended->toArray();
        $this->assertCount(2, $emails);
        $this->assertSame($existing, $emails[0]);
        $this->assertSame('john.doe@example.com', $emails[0]->getEmail());
        $this->assertNull($emails[1]->getUid());
        $this->assertSame(12, $emails[1]->getPid());
        $this->assertSame('jane.doe@example.com', $emails[1]->getEmail());
    }

    /**
     * Mapping does not assign the produced set to the parent. Reordering a live
     * collection means detaching and re-attaching every member, and doing it
     * here would make the mapper the thing that decides sorting.
     */
    #[Test]
    public function mappingAChildCollectionDoesNotAssignItToTheParent(): void
    {
        $profile = new Profile();
        $profile->setPid(12);

        $intended = $this->subject->mapAddresses($profile, ['new-1' => new AddressData(line1: 'Example Street 1')], []);

        $this->assertCount(1, $intended);
        $this->assertCount(0, $profile->getAddresses());
    }
}
