<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Domain\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\EmailDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The e-mail half of the mapping layer.
 *
 * The class is structurally identical to `AddressDataMapper` and deliberately
 * not shared with it, so the contract is asserted a second time rather than
 * assumed: the exception codes differ per class, and a copy that drifts is
 * exactly what a shared test would hide.
 */
final class EmailDataMapperTest extends UnitTestCase
{
    private EmailDataMapper $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new EmailDataMapper();
    }

    #[Test]
    public function mapWritesEveryWritableProperty(): void
    {
        $email = new Email();

        $this->subject->map(new EmailData(type: 'business', email: 'john.doe@example.com'), $email);

        $this->assertSame('business', $email->getType());
        $this->assertSame('john.doe@example.com', $email->getEmail());
    }

    #[Test]
    public function applyPropertyLeavesEveryOtherPropertyUntouched(): void
    {
        $email = new Email();
        $email->setType('business');
        $email->setEmail('john.doe@example.com');

        $this->subject->applyProperty($email, 'email', 'jane.doe@example.com');

        $this->assertSame('jane.doe@example.com', $email->getEmail());
        $this->assertSame('business', $email->getType());
    }

    #[Test]
    #[DataProvider('propertyNamesThatAreNotWritable')]
    public function applyPropertyRejectsAPropertyItCannotWrite(string $propertyName): void
    {
        $email = new Email();
        $email->setPid(12);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492121);

        try {
            $this->subject->applyProperty($email, $propertyName, 5);
        } finally {
            $this->assertSame(12, $email->getPid());
            $this->assertNull($email->getUid());
            $this->assertFalse($email->isHidden());
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

    #[Test]
    #[DataProvider('valuesThatAreNotAString')]
    public function applyPropertyRejectsANonStringValue(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492122);

        $this->subject->applyProperty(new Email(), 'email', $value);
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

    #[Test]
    public function anExistingChildIsUpdatedInPlace(): void
    {
        $existing = $this->persistedEmail(5, 'john.doe@example.com');

        $intended = $this->subject->mapCollection(
            $this->profileOnPage(12),
            [5 => new EmailData(type: 'private', email: 'jane.doe@example.com')],
            [$existing],
        );

        $this->assertSame([$existing], $intended->toArray());
        $this->assertSame('private', $existing->getType());
        $this->assertSame('jane.doe@example.com', $existing->getEmail());
    }

    #[Test]
    public function aKeyThatIsNotAPositiveIntegerCreatesANewChildOnThePageOfItsParent(): void
    {
        $existing = $this->persistedEmail(5, 'john.doe@example.com');

        $intended = $this->subject->mapCollection(
            $this->profileOnPage(12),
            ['new-1' => new EmailData(email: 'jane.doe@example.com')],
            [$existing],
        );

        $created = $intended->toArray();
        $this->assertCount(1, $created);
        $this->assertNotSame($existing, $created[0]);
        $this->assertNull($created[0]->getUid());
        $this->assertSame(12, $created[0]->getPid());
        $this->assertSame('john.doe@example.com', $existing->getEmail());
    }

    #[Test]
    public function aKeyOutsideTheAddressableSetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492123);

        $this->subject->mapCollection(
            $this->profileOnPage(12),
            [99 => new EmailData(email: 'jane.doe@example.com')],
            [$this->persistedEmail(5, 'john.doe@example.com')],
        );
    }

    #[Test]
    public function anUnpersistedExistingChildIsNotAddressable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1786492123);

        $this->subject->mapCollection(
            $this->profileOnPage(12),
            [5 => new EmailData(email: 'jane.doe@example.com')],
            [new Email()],
        );
    }

    #[Test]
    public function anExistingChildThatWasNotSubmittedIsNeitherReturnedNorMutated(): void
    {
        $submittedChild = $this->persistedEmail(5, 'john.doe@example.com');
        $untouchedChild = $this->persistedEmail(6, 'jane.doe@example.com');

        $intended = $this->subject->mapCollection(
            $this->profileOnPage(12),
            [5 => new EmailData(type: 'private', email: 'john.new@example.com')],
            [$submittedChild, $untouchedChild],
        );

        $this->assertSame([$submittedChild], $intended->toArray());
        $this->assertSame('jane.doe@example.com', $untouchedChild->getEmail());
        $this->assertSame('others', $untouchedChild->getType());
        $this->assertSame(6, $untouchedChild->getUid());
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

    private function persistedEmail(int $uid, string $email): Email
    {
        $model = new Email();
        $model->_setProperty('uid', $uid);
        $model->setEmail($email);

        return $model;
    }
}
