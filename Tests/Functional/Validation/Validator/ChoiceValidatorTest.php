<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Validation\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractFunctionalTestCase;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\ChoiceValidator;
use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Validation\Exception\InvalidValidationOptionsException;

/**
 * The "one of a fixed set" validator TYPO3 does not ship.
 *
 * Functional rather than unit, for the reason `DtoValidatorTest` documents:
 * `translateErrorMessage()` needs a container, so a rule that fires cannot be
 * exercised without one.
 */
final class ChoiceValidatorTest extends AbstractFunctionalTestCase
{
    /**
     * @var list<string>
     */
    private const CHOICES = ['home', 'work', 'others'];

    #[Test]
    #[DataProvider('acceptedValues')]
    public function aValueInsideTheAcceptedSetPasses(string $value): void
    {
        $this->assertSame([], $this->errorCodes(self::CHOICES, $value));
    }

    /**
     * @return \Generator<string, array{value: string}>
     */
    public static function acceptedValues(): \Generator
    {
        foreach (self::CHOICES as $choice) {
            yield $choice => ['value' => $choice];
        }
    }

    #[Test]
    public function aValueOutsideTheAcceptedSetIsRejected(): void
    {
        $this->assertSame([1786492302], $this->errorCodes(self::CHOICES, 'nope'));
    }

    /**
     * `$acceptsEmptyValues` is `false`, which is what makes `null` and `''`
     * reach `isValid()` at all — `AbstractValidator::validate()` skips it for
     * an empty value otherwise.
     *
     * For a select that is the only correct answer: the empty string is not one
     * of the choices unless it is listed as one, and accepting it would let a
     * partial save write `''` into a column whose TCA pins a non-empty default.
     */
    #[Test]
    #[DataProvider('emptyValues')]
    public function anEmptyValueIsRejectedRatherThanSkipped(mixed $value): void
    {
        $this->assertSame([1786492302], $this->errorCodes(self::CHOICES, $value));
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function emptyValues(): \Generator
    {
        yield 'the empty string' => ['value' => ''];
        yield 'null' => ['value' => null];
    }

    /**
     * The empty string is rejected because it is not in the set, not because it
     * is empty: a set that lists it accepts it.
     */
    #[Test]
    public function anEmptyStringIsAcceptedWhenItIsOneOfTheChoices(): void
    {
        $this->assertSame([], $this->errorCodes(['', 'home'], ''));
    }

    /**
     * Partial validation runs the leaf validators against the raw submitted
     * value, so every validator in a rule set has to tolerate `mixed`. This is
     * why `RegularExpressionValidator` is unusable at this boundary: it calls
     * `preg_match()` under `strict_types=1` and turns `{"type": 7}` into a 500.
     */
    #[Test]
    #[DataProvider('valuesOfAnotherType')]
    public function aValueOfAnotherTypeIsAValidationErrorRatherThanATypeError(mixed $value): void
    {
        $this->assertSame([1786492302], $this->errorCodes(self::CHOICES, $value));
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function valuesOfAnotherType(): \Generator
    {
        yield 'JSON number' => ['value' => 7];
        yield 'JSON float' => ['value' => 4.2];
        yield 'JSON true' => ['value' => true];
        yield 'JSON false' => ['value' => false];
        yield 'JSON array' => ['value' => ['home']];
    }

    /**
     * The comparison is strict, so a numeric choice set is not silently matched
     * by the string a JSON payload happens to carry, and vice versa.
     *
     * @param array<int, mixed> $choices
     */
    #[Test]
    #[DataProvider('valuesEqualButNotIdenticalToAChoice')]
    public function theComparisonIsStrict(array $choices, mixed $value): void
    {
        $this->assertSame([1786492302], $this->errorCodes($choices, $value));
    }

    /**
     * @return \Generator<string, array{choices: array<int, mixed>, value: mixed}>
     */
    public static function valuesEqualButNotIdenticalToAChoice(): \Generator
    {
        yield 'a number against a set of strings' => ['choices' => ['1', '2'], 'value' => 1];
        yield 'a string against a set of numbers' => ['choices' => [1, 2], 'value' => '1'];
        yield 'true against a non-empty string' => ['choices' => ['home'], 'value' => true];
        yield 'zero against the empty string' => ['choices' => [''], 'value' => 0];
    }

    /**
     * A choice set is not optional. A validator whose set is missing would
     * accept nothing at all, which fails closed but silently.
     */
    #[Test]
    public function theChoicesOptionIsRequired(): void
    {
        $this->expectException(InvalidValidationOptionsException::class);
        $this->expectExceptionCode(1379981891);

        (new ChoiceValidator())->setOptions([]);
    }

    /**
     * `AbstractValidator` checks that an option is *set*, never what type it
     * has, so the type check has to happen where the option is read.
     */
    #[Test]
    public function aChoiceSetThatIsNotAnArrayIsRejected(): void
    {
        $validator = new ChoiceValidator();
        $validator->setOptions(['choices' => 'home,work']);

        $this->expectException(InvalidValidationOptionsException::class);
        $this->expectExceptionCode(1786492301);

        $validator->validate('home');
    }

    /**
     * @param array<int, mixed> $choices
     * @return list<int>
     */
    private function errorCodes(array $choices, mixed $value): array
    {
        $validator = new ChoiceValidator();
        $validator->setOptions(['choices' => $choices]);

        return array_values(array_map(
            static fn(Error $error): int => $error->getCode(),
            $validator->validate($value)->getErrors(),
        ));
    }
}
