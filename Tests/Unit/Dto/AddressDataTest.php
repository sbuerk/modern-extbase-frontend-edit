<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The payload object of an address save.
 *
 * A 1:n child payload deliberately carries no identity of its own — the row it
 * updates is resolved out of band from the owned set, never from the payload —
 * so the assertions about `uid` are the same ones {@see ProfileDataTest} makes
 * and matter for the same reason.
 */
final class AddressDataTest extends UnitTestCase
{
    #[Test]
    public function fromArrayReadsEveryKnownKey(): void
    {
        $subject = AddressData::fromArray([
            'type' => 'work',
            'line1' => 'Example Street 1',
            'line2' => '12345 Example City',
        ]);

        $this->assertSame('work', $subject->type);
        $this->assertSame('Example Street 1', $subject->line1);
        $this->assertSame('12345 Example City', $subject->line2);
    }

    #[Test]
    public function theWritableSurfaceIsTheConstructorParameterList(): void
    {
        $constructor = (new \ReflectionClass(AddressData::class))->getConstructor();
        $this->assertNotNull($constructor);

        $this->assertSame(
            ['type', 'line1', 'line2'],
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
            property_exists(AddressData::class, $propertyName),
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
        $subject = AddressData::fromArray([
            'line1' => 'Example Street 1',
            'uid' => 99,
            'pid' => 42,
            'sys_language_uid' => 3,
            'hidden' => false,
        ]);

        $this->assertEquals(new AddressData(line1: 'Example Street 1'), $subject);
    }

    /**
     * The `type` default mirrors the TCA default of the column, which is pinned
     * to `DEFAULT 'others'` in `ext_tables.sql`.
     */
    #[Test]
    public function anAbsentSelectKeyFallsBackToTheColumnDefault(): void
    {
        $this->assertSame('others', AddressData::fromArray([])->type);
        $this->assertSame('others', (new AddressData())->type);
    }

    /**
     * A wrongly typed select value becomes `''` rather than the default, so
     * `ChoiceValidator` rejects it instead of the hydration substituting a
     * value that would validate.
     */
    #[Test]
    #[DataProvider('nonStringPayloadValues')]
    public function aPresentButNonStringSelectValueBecomesTheEmptyString(mixed $value): void
    {
        $this->assertSame('', AddressData::fromArray(['type' => $value])->type);
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function nonStringPayloadValues(): \Generator
    {
        yield 'JSON number' => ['value' => 7];
        yield 'JSON true' => ['value' => true];
        yield 'JSON null' => ['value' => null];
        yield 'JSON array' => ['value' => ['home']];
    }

    #[Test]
    public function anAbsentKeyFallsBackToTheDeclaredDefault(): void
    {
        $this->assertEquals(new AddressData(), AddressData::fromArray([]));
    }

    #[Test]
    public function thePayloadObjectIsExcludedFromTheContainer(): void
    {
        $this->assertCount(1, (new \ReflectionClass(AddressData::class))->getAttributes(Exclude::class));
    }
}
