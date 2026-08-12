<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The payload object of a profile save.
 *
 * The tests below are mostly about what the class does **not** do: it has no
 * `uid` and no `pid`, it never grows a property from a payload key, and it
 * never lets a non-string JSON value reach a `string` parameter. Those are the
 * properties `docs/frontend-edit/dto-and-validation.md` calls the reason the
 * DTO is not the entity, and each of them is silent when it breaks.
 */
final class ProfileDataTest extends UnitTestCase
{
    #[Test]
    public function fromArrayReadsEveryKnownKey(): void
    {
        $subject = ProfileData::fromArray([
            'shortname' => 'jdoe',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'birthday' => '1980-05-17',
            'bio' => 'Hello.',
        ]);

        $this->assertSame('jdoe', $subject->shortname);
        $this->assertSame('John', $subject->firstname);
        $this->assertSame('Doe', $subject->lastname);
        $this->assertSame('1980-05-17', $subject->birthday);
        $this->assertSame('Hello.', $subject->bio);
    }

    /**
     * The whitelist is the constructor parameter list, so it is asserted as a
     * list rather than property by property: a property added without a test
     * being touched is exactly the drift this pins.
     */
    #[Test]
    public function theWritableSurfaceIsTheConstructorParameterList(): void
    {
        $constructor = (new \ReflectionClass(ProfileData::class))->getConstructor();
        $this->assertNotNull($constructor);

        $this->assertSame(
            ['shortname', 'firstname', 'lastname', 'birthday', 'bio'],
            array_map(
                static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            ),
        );
    }

    /**
     * `pid` in particular must not exist as a property at all rather than
     * merely be ignored: `setPid()` is public on every Extbase model and
     * `Backend::determineStoragePageIdForNewRecord()` prefers the object's own
     * pid over every configuration source.
     */
    #[Test]
    #[DataProvider('propertyNamesThatMustNotExist')]
    public function theRecordIdentityIsNotPartOfThePayload(string $propertyName): void
    {
        $this->assertFalse(
            property_exists(ProfileData::class, $propertyName),
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
        yield 'ownership: feUser' => ['propertyName' => 'feUser'];
        yield 'publication state: hidden' => ['propertyName' => 'hidden'];
    }

    /**
     * Mass assignment at the hydration level: the payload carries everything a
     * client could think of and the object is indistinguishable from one built
     * without any of it.
     */
    #[Test]
    public function fromArrayIgnoresEveryKeyItDoesNotDeclare(): void
    {
        $subject = ProfileData::fromArray([
            'shortname' => 'jdoe',
            'uid' => 99,
            'pid' => 42,
            'sys_language_uid' => 3,
            'feUser' => 7,
            'hidden' => false,
            '__type' => 'SomethingElse',
        ]);

        $this->assertEquals(new ProfileData(shortname: 'jdoe'), $subject);
    }

    /**
     * A present-but-wrongly-typed value becomes `''`, which is a rejection
     * handed to the rule set rather than a fallback: `shortname` then fails its
     * `NotEmptyValidator` instead of this method quietly substituting something
     * that validates.
     *
     * Casting is not an option — `(string)[]` emits "Array to string
     * conversion" and this repository's suites fail on a warning.
     */
    #[Test]
    #[DataProvider('nonStringPayloadValues')]
    public function aPresentButNonStringValueBecomesTheEmptyString(mixed $value): void
    {
        $this->assertSame('', ProfileData::fromArray(['shortname' => $value])->shortname);
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function nonStringPayloadValues(): \Generator
    {
        yield 'JSON number' => ['value' => 42];
        yield 'JSON float' => ['value' => 4.2];
        yield 'JSON true' => ['value' => true];
        yield 'JSON false' => ['value' => false];
        yield 'JSON null' => ['value' => null];
        yield 'JSON array' => ['value' => ['x']];
        yield 'JSON object' => ['value' => ['key' => 'value']];
    }

    /**
     * An absent key is a legitimate state and yields the declared default,
     * which is a different case from a present but unusable value.
     */
    #[Test]
    public function anAbsentKeyFallsBackToTheDeclaredDefault(): void
    {
        $this->assertEquals(new ProfileData(), ProfileData::fromArray([]));
    }

    /**
     * The payload keeps the wire string exactly as it arrived.
     *
     * There is deliberately no parsing helper on this class. The mapper is the
     * single place that turns the wire format into a date, because the partial
     * save path only ever sees the raw string, and a second converter here
     * would give the two paths different behaviour on an unparseable value —
     * this one would have to answer `null` where the mapper throws.
     */
    #[Test]
    public function theBirthdayIsKeptAsTheSubmittedWireString(): void
    {
        $this->assertSame('1980-05-17', ProfileData::fromArray(['birthday' => '1980-05-17'])->birthday);
    }

    /**
     * `#[Exclude]` is load-bearing rather than decorative:
     * `ObjectConverter::buildObject()` answers a container-known class with a
     * container-built instance, and every submitted value is then silently
     * dropped. `Configuration/Services.php` loads the whole `Classes/` tree, so
     * without the attribute this class is known to the container.
     */
    #[Test]
    public function thePayloadObjectIsExcludedFromTheContainer(): void
    {
        $this->assertCount(1, (new \ReflectionClass(ProfileData::class))->getAttributes(Exclude::class));
    }
}
