<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractFunctionalTestCase;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\ChoiceValidator;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\DateStringValidator;

/**
 * The one container fact of this layer that a functional test can actually
 * assert.
 *
 * ## Why the `#[Exclude]` side is asserted by reflection instead
 *
 * The obvious test — "the container does not know `ProfileData`" — cannot fail
 * and is therefore not written here. `Configuration/Services.php` registers
 * everything below `Classes/` as a **private** service, and Symfony's
 * `RemoveUnusedDefinitionsPass` deletes a private service that nothing
 * references. The testing framework's private container then drops it as well;
 * `PrivateContainerRealRefPass` explicitly `unset()`s every private service
 * whose definition is gone.
 *
 * A payload object is referenced by nothing, so it is absent from the container
 * with or without `#[Exclude]` and `$this->has()` answers `false` either way.
 * The attribute is pinned where it can still fail — by reflection, in the DTO
 * and rule set unit tests.
 *
 * The same removal is why the mappers and `DtoValidator` are not fetched here:
 * nothing injects them until the write path exists. Their constructor wiring is
 * not unasserted, though — autowiring resolves before the removal pass, so an
 * argument the container cannot satisfy fails container compilation, which is
 * exactly what `ExtensionLoadedTest` boots an instance to prove.
 */
final class ValidationWiringTest extends AbstractFunctionalTestCase
{
    /**
     * A custom validator is the documented exception to the data-object rule,
     * and this is the behaviour that exception buys.
     *
     * Extbase autoconfigures every `ValidatorInterface` implementation and runs
     * `PublicServicePass('extbase.validator', true)` over it, which makes it
     * public — so it survives the removal pass — **and non-shared**, so every
     * `GeneralUtility::makeInstance()` yields a fresh instance and the mutable
     * `$result` and `$options` of `AbstractValidator` cannot bleed from one
     * validation into the next. `DtoValidator` builds a validator per rule per
     * call and relies on both.
     *
     * `#[Exclude]` on such a class would remove it from the container and break
     * them without any test of the rules noticing.
     *
     * @param class-string $className
     */
    #[Test]
    #[DataProvider('theCustomValidators')]
    public function aCustomValidatorIsAPublicNonSharedService(string $className): void
    {
        $this->assertTrue($this->has($className), sprintf('"%s" is not registered in the container.', $className));
        $this->assertInstanceOf($className, $this->get($className));
        $this->assertNotSame(
            $this->get($className),
            $this->get($className),
            sprintf('"%s" is shared, so validator state can bleed between two validations.', $className),
        );
    }

    /**
     * @return \Generator<string, array{className: class-string}>
     */
    public static function theCustomValidators(): \Generator
    {
        yield 'choice' => ['className' => ChoiceValidator::class];
        yield 'date string' => ['className' => DateStringValidator::class];
    }
}
