<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The payload object of an e-mail address save.
 *
 * Same shape and same guarantees as {@see AddressDataTest}: no identity in the
 * payload, only declared keys are read, and a wrongly typed value is rejected
 * rather than coerced.
 */
final class EmailDataTest extends UnitTestCase
{
    #[Test]
    public function fromArrayReadsEveryKnownKey(): void
    {
        $subject = EmailData::fromArray([
            'type' => 'business',
            'email' => 'john.doe@example.com',
        ]);

        $this->assertSame('business', $subject->type);
        $this->assertSame('john.doe@example.com', $subject->email);
    }

    #[Test]
    public function theWritableSurfaceIsTheConstructorParameterList(): void
    {
        $constructor = (new \ReflectionClass(EmailData::class))->getConstructor();
        $this->assertNotNull($constructor);

        $this->assertSame(
            ['type', 'email'],
            array_map(
                static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            ),
        );
    }

    #[Test]
    #[DataProvider('propertyNamesThatMustNotExist')]
    public function theRecordIdentityIsNotPartOfThePayload(string $propertyName): void
    {
        $this->assertFalse(
            property_exists(EmailData::class, $propertyName),
            sprintf('"%s" must not be a property of the payload object.', $propertyName),
        );
    }

    /**
     * @return \Generator<string, array{propertyName: string}>
     */
    public static function propertyNamesThatMustNotExist(): \Generator
    {
        yield 'record identity: uid' => ['propertyName' => 'uid'];
        yield 'storage location: pid' => ['propertyName' => 'pid'];
        yield 'language: sys_language_uid' => ['propertyName' => 'sys_language_uid'];
        yield 'publication state: hidden' => ['propertyName' => 'hidden'];
    }

    #[Test]
    public function fromArrayIgnoresEveryKeyItDoesNotDeclare(): void
    {
        $subject = EmailData::fromArray([
            'email' => 'john.doe@example.com',
            'uid' => 99,
            'pid' => 42,
            'sys_language_uid' => 3,
            'hidden' => false,
        ]);

        $this->assertEquals(new EmailData(email: 'john.doe@example.com'), $subject);
    }

    #[Test]
    public function anAbsentSelectKeyFallsBackToTheColumnDefault(): void
    {
        $this->assertSame('others', EmailData::fromArray([])->type);
        $this->assertSame('others', (new EmailData())->type);
    }

    #[Test]
    #[DataProvider('nonStringPayloadValues')]
    public function aPresentButNonStringSelectValueBecomesTheEmptyString(mixed $value): void
    {
        $this->assertSame('', EmailData::fromArray(['type' => $value])->type);
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function nonStringPayloadValues(): \Generator
    {
        yield 'JSON number' => ['value' => 7];
        yield 'JSON true' => ['value' => true];
        yield 'JSON null' => ['value' => null];
        yield 'JSON array' => ['value' => ['private']];
    }

    #[Test]
    public function anAbsentKeyFallsBackToTheDeclaredDefault(): void
    {
        $this->assertEquals(new EmailData(), EmailData::fromArray([]));
    }

    #[Test]
    public function thePayloadObjectIsExcludedFromTheContainer(): void
    {
        $this->assertCount(1, (new \ReflectionClass(EmailData::class))->getAttributes(Exclude::class));
    }
}
