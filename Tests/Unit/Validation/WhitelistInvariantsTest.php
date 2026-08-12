<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\AddressDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\EmailDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\ProfileDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use SBUERK\ModernExtbaseFrontendEdit\Validation\AddressRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\EmailRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\ProfileRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\RuleSetInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The three lists that describe one payload, and the invariants between them.
 *
 * A rule set, a mapper dispatch table and a DTO constructor each enumerate the
 * writable fields of the same object. `docs/frontend-edit/dto-and-validation.md`
 * makes the rule set the single whitelist, but the other two lists still exist,
 * and nothing in the type system keeps them aligned.
 *
 * Both invariants below fail **silently** when they drift — the first one at
 * runtime with an exception the user sees, the second one by declaring a field
 * writable that no payload can carry — which is why they are pinned here rather
 * than trusted.
 */
final class WhitelistInvariantsTest extends UnitTestCase
{
    /**
     * The dangerous direction. A property that carries rules but is missing
     * from the mapper's dispatch table is validated successfully and then
     * throws in `applyProperty()`, on the write path, after the caller has
     * already been told the payload is fine.
     *
     * The reverse is not a hole: a writable property without rules cannot be
     * addressed at all, because a partial save naming it is rejected by the
     * rule set before it reaches the mapper.
     *
     * @param list<string> $writableProperties
     */
    #[Test]
    #[DataProvider('ruleSetsAndTheirMappers')]
    public function everyValidatedPropertyIsOneTheMapperCanWrite(
        RuleSetInterface $ruleSet,
        array $writableProperties,
    ): void {
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($ruleSet->rules()), $writableProperties)),
            sprintf(
                '%s declares rules for properties the mapper cannot write, which throws on the write path.',
                $ruleSet::class,
            ),
        );
    }

    /**
     * @return \Generator<string, array{ruleSet: RuleSetInterface, writableProperties: list<string>}>
     */
    public static function ruleSetsAndTheirMappers(): \Generator
    {
        yield 'profile' => [
            'ruleSet' => new ProfileRuleSet(),
            'writableProperties' => ProfileDataMapper::WRITABLE_PROPERTIES,
        ];
        yield 'address' => [
            'ruleSet' => new AddressRuleSet(),
            'writableProperties' => AddressDataMapper::WRITABLE_PROPERTIES,
        ];
        yield 'email' => [
            'ruleSet' => new EmailRuleSet(),
            'writableProperties' => EmailDataMapper::WRITABLE_PROPERTIES,
        ];
    }

    /**
     * The mapper writes what a full submit carries, and a full submit carries
     * exactly the DTO's constructor parameters. A dispatch table entry without
     * a matching parameter is a `case` that the full path can never reach —
     * `valueOf()` would have to invent the value.
     *
     * @param list<string> $writableProperties
     * @param class-string $dtoClassName
     */
    #[Test]
    #[DataProvider('mappersAndTheirPayloadObjects')]
    public function everyWritablePropertyIsCarriedByThePayloadObject(
        array $writableProperties,
        string $dtoClassName,
    ): void {
        $constructor = (new \ReflectionClass($dtoClassName))->getConstructor();
        $this->assertNotNull($constructor);

        $parameterNames = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

        $this->assertSame(
            [],
            array_values(array_diff($writableProperties, $parameterNames)),
            sprintf('The mapper writes properties "%s" does not carry.', $dtoClassName),
        );
    }

    /**
     * @return \Generator<string, array{writableProperties: list<string>, dtoClassName: class-string}>
     */
    public static function mappersAndTheirPayloadObjects(): \Generator
    {
        yield 'profile' => [
            'writableProperties' => ProfileDataMapper::WRITABLE_PROPERTIES,
            'dtoClassName' => ProfileData::class,
        ];
        yield 'address' => [
            'writableProperties' => AddressDataMapper::WRITABLE_PROPERTIES,
            'dtoClassName' => AddressData::class,
        ];
        yield 'email' => [
            'writableProperties' => EmailDataMapper::WRITABLE_PROPERTIES,
            'dtoClassName' => EmailData::class,
        ];
    }

    /**
     * Full validation reads the property values through `ObjectAccess`, so a
     * rule set naming a property the payload object does not expose surfaces as
     * the core `\RuntimeException` 1546632293 — at request time, for whichever
     * save happens to be the first one.
     *
     * @param class-string $dtoClassName
     */
    #[Test]
    #[DataProvider('ruleSetsAndTheirPayloadObjects')]
    public function everyValidatedPropertyExistsOnThePayloadObject(
        RuleSetInterface $ruleSet,
        string $dtoClassName,
    ): void {
        foreach (array_keys($ruleSet->rules()) as $propertyName) {
            $this->assertTrue(
                property_exists($dtoClassName, $propertyName),
                sprintf('"%s" has no property "%s".', $dtoClassName, $propertyName),
            );
        }
    }

    /**
     * Every rule names a class that exists and is a validator. The rule set is
     * data, so a typo in a class name is not a compile error and would only
     * surface when that one rule fires.
     */
    #[Test]
    #[DataProvider('ruleSets')]
    public function everyRuleNamesAValidator(RuleSetInterface $ruleSet): void
    {
        foreach ($ruleSet->rules() as $propertyName => $rules) {
            foreach ($rules as [$validatorClassName]) {
                $this->assertTrue(
                    class_exists($validatorClassName),
                    sprintf(
                        'Rule "%s" of %s names "%s", which does not exist.',
                        $propertyName,
                        $ruleSet::class,
                        $validatorClassName,
                    ),
                );
                $this->assertContains(
                    ValidatorInterface::class,
                    class_implements($validatorClassName) ?: [],
                    sprintf(
                        'Rule "%s" of %s names "%s", which is not a validator.',
                        $propertyName,
                        $ruleSet::class,
                        $validatorClassName,
                    ),
                );
            }
        }
    }

    /**
     * A rule set is data, not a service: it is created with `new` by whoever
     * needs it, and the container must not know it.
     */
    #[Test]
    #[DataProvider('ruleSets')]
    public function aRuleSetIsExcludedFromTheContainer(RuleSetInterface $ruleSet): void
    {
        $this->assertCount(1, (new \ReflectionClass($ruleSet))->getAttributes(Exclude::class));
    }

    /**
     * @return \Generator<string, array{ruleSet: RuleSetInterface, dtoClassName: class-string}>
     */
    public static function ruleSetsAndTheirPayloadObjects(): \Generator
    {
        yield 'profile' => ['ruleSet' => new ProfileRuleSet(), 'dtoClassName' => ProfileData::class];
        yield 'address' => ['ruleSet' => new AddressRuleSet(), 'dtoClassName' => AddressData::class];
        yield 'email' => ['ruleSet' => new EmailRuleSet(), 'dtoClassName' => EmailData::class];
    }

    /**
     * @return \Generator<string, array{ruleSet: RuleSetInterface}>
     */
    public static function ruleSets(): \Generator
    {
        yield 'profile' => ['ruleSet' => new ProfileRuleSet()];
        yield 'address' => ['ruleSet' => new AddressRuleSet()];
        yield 'email' => ['ruleSet' => new EmailRuleSet()];
    }
}
