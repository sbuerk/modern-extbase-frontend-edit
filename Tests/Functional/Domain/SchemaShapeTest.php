<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Domain;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\AddressRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\EmailRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The three schema decisions that were taken for database portability, checked
 * against a running database rather than against the reasoning that produced
 * them.
 *
 * All three are invisible on SQLite alone, which is why this class is named in
 * the contribution notes as one to run on `-d mariadb` and `-d postgres` as
 * well:
 *
 * - **`birthday`** is a native `DATE` column and nullable. The "empty" value of
 *   a non-nullable native date field in TYPO3 is the literal `'0000-00-00'`,
 *   which PostgreSQL rejects outright while SQLite and MariaDB accept it.
 * - **`bio`** is a nullable `TEXT` without a default, because MySQL refuses a
 *   literal `DEFAULT` on `BLOB`/`TEXT`/`JSON` columns (error 1101) and Doctrine
 *   emits one unconditionally for string-ish types. The `''` invariant lives in
 *   the model instead.
 * - **`type`** is pinned in `ext_tables.sql`, because the auto-generated
 *   definition of a `type=select` column carries `DEFAULT ''` on TYPO3 v13 and
 *   the TCA default on v14. The column default is what an `INSERT` that omits
 *   the column gets, so it is asserted through such an insert rather than
 *   through the model.
 */
final class SchemaShapeTest extends AbstractProfileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/Profiles.csv');
    }

    #[Test]
    public function birthdayIsReadBackAsADateTimeImmutable(): void
    {
        $birthday = null;
        $this->executeInFrontendContext(function () use (&$birthday): void {
            $profile = $this->get(ProfileRepository::class)->findByUid(1);
            $this->assertInstanceOf(Profile::class, $profile);
            $birthday = $profile->getBirthday();
        });

        $this->assertInstanceOf(\DateTimeImmutable::class, $birthday);
        $this->assertSame('1980-05-17', $birthday->format('Y-m-d'));
    }

    /**
     * The column is `DATE DEFAULT NULL`, and `null` has to survive both
     * directions — reading a row that has none, and writing one back.
     */
    #[Test]
    public function birthdayRoundTripsAsNull(): void
    {
        $readBack = 'not executed';
        $this->executeInFrontendContext(function () use (&$readBack): void {
            $profileRepository = $this->get(ProfileRepository::class);
            $persistenceManager = $this->get(PersistenceManagerInterface::class);

            $profile = $profileRepository->findByUid(7);
            $this->assertInstanceOf(Profile::class, $profile);
            $this->assertNull($profile->getBirthday(), 'A row without a birthday reads as null, not as an epoch date.');

            $profile->setBirthday(new \DateTimeImmutable('2001-02-03'));
            $profileRepository->update($profile);
            $persistenceManager->persistAll();

            $stored = $profileRepository->findByUid(1);
            $this->assertInstanceOf(Profile::class, $stored);
            $stored->setBirthday(null);
            $profileRepository->update($stored);
            $persistenceManager->persistAll();
            $persistenceManager->clearState();

            $reloaded = $profileRepository->findByUid(7);
            $this->assertInstanceOf(Profile::class, $reloaded);
            $readBack = $reloaded->getBirthday()?->format('Y-m-d');
        });

        $this->assertSame('2001-02-03', $readBack);

        $birthdays = $this->readColumnByUid(self::PROFILE_TABLE, 'birthday');
        $this->assertNull($birthdays[1], 'A birthday set to null is stored as SQL NULL, not as "0000-00-00".');
        $this->assertSame('2001-02-03', $birthdays[7]);
    }

    /**
     * The `''` invariant of `bio` is enforced by the model, and the column is
     * nullable — so an empty biography has to survive a write and come back as
     * `''` rather than as `null`.
     */
    #[Test]
    public function bioAcceptsAndReturnsAnEmptyString(): void
    {
        $readBack = 'not executed';
        $this->executeInFrontendContext(function () use (&$readBack): void {
            $profileRepository = $this->get(ProfileRepository::class);
            $persistenceManager = $this->get(PersistenceManagerInterface::class);

            $profile = $profileRepository->findByUid(1);
            $this->assertInstanceOf(Profile::class, $profile);
            $this->assertSame('', $profile->getBio());

            $profile->setBio('A biography.');
            $profileRepository->update($profile);
            $persistenceManager->persistAll();

            $profile->setBio('');
            $profileRepository->update($profile);
            $persistenceManager->persistAll();
            $persistenceManager->clearState();

            $reloaded = $profileRepository->findByUid(1);
            $this->assertInstanceOf(Profile::class, $reloaded);
            $readBack = $reloaded->getBio();
        });

        $this->assertSame('', $readBack);
        $this->assertSame('', $this->readColumnByUid(self::PROFILE_TABLE, 'bio')[1]);
    }

    /**
     * A row whose `bio` column is SQL `NULL` — which the schema allows, and
     * which any `INSERT` omitting the column produces — still reads as `''`.
     * The typed property could not hold `null` at all, so this is the case that
     * decides whether the invariant holds for foreign data as well.
     */
    #[Test]
    public function bioOfARowStoredAsNullReadsAsAnEmptyString(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::PROFILE_TABLE);
        $connection->update(self::PROFILE_TABLE, ['bio' => null], ['uid' => 1]);

        $readBack = 'not executed';
        $this->executeInFrontendContext(function () use (&$readBack): void {
            $profile = $this->get(ProfileRepository::class)->findByUid(1);
            $this->assertInstanceOf(Profile::class, $profile);
            $readBack = $profile->getBio();
        });

        $this->assertSame('', $readBack);
    }

    /**
     * The database default of the pinned `type` columns.
     *
     * The insert deliberately omits the column, because that is the only way
     * the column default is used at all — every write through the models
     * carries the property default with it.
     */
    #[Test]
    public function typeColumnsDefaultToOthers(): void
    {
        $addressConnection = $this->getConnectionPool()->getConnectionForTable(self::ADDRESS_TABLE);
        $addressConnection->insert(self::ADDRESS_TABLE, ['pid' => self::STORAGE_PAGE_ID, 'line1' => 'No type given']);
        $addressUid = (int)$addressConnection->lastInsertId();

        $emailConnection = $this->getConnectionPool()->getConnectionForTable(self::EMAIL_TABLE);
        $emailConnection->insert(self::EMAIL_TABLE, ['pid' => self::STORAGE_PAGE_ID, 'email' => 'no-type@example.org']);
        $emailUid = (int)$emailConnection->lastInsertId();

        $this->assertSame('others', $this->readColumnByUid(self::ADDRESS_TABLE, 'type')[$addressUid]);
        $this->assertSame('others', $this->readColumnByUid(self::EMAIL_TABLE, 'type')[$emailUid]);
    }

    /**
     * The model defaults mirror the column defaults, so a child created through
     * the domain layer without an explicit type is stored the same way as one
     * created by an `INSERT` that omits the column.
     */
    #[Test]
    public function newChildrenAreStoredWithTheDefaultType(): void
    {
        $this->executeInFrontendContext(function (): void {
            $persistenceManager = $this->get(PersistenceManagerInterface::class);

            $address = new Address();
            $address->setLine1('Created through the domain layer');
            $this->assertSame('others', $address->getType());
            $this->get(AddressRepository::class)->add($address);

            $email = new Email();
            $email->setEmail('created@example.org');
            $this->assertSame('others', $email->getType());
            $this->get(EmailRepository::class)->add($email);

            $persistenceManager->persistAll();
        });

        $this->assertContains('others', $this->readColumnByUid(self::ADDRESS_TABLE, 'type'));
        $this->assertContains('others', $this->readColumnByUid(self::EMAIL_TABLE, 'type'));
    }
}
