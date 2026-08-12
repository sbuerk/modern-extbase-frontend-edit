<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Validation\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractFunctionalTestCase;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\DateStringValidator;
use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Validation\Exception\InvalidValidationOptionsException;

/**
 * The date **string** validator, and the overflow it exists to catch.
 *
 * Functional rather than unit, for the reason `DtoValidatorTest` documents:
 * `translateErrorMessage()` needs a container, so a rule that fires cannot be
 * exercised without one.
 */
final class DateStringValidatorTest extends AbstractFunctionalTestCase
{
    #[Test]
    #[DataProvider('datesInTheWireFormat')]
    public function aDateInTheConfiguredFormatPasses(string $value): void
    {
        $this->assertSame([], $this->errorCodes(ProfileData::BIRTHDAY_FORMAT, $value));
    }

    /**
     * @return \Generator<string, array{value: string}>
     */
    public static function datesInTheWireFormat(): \Generator
    {
        yield 'a plain date' => ['value' => '1980-05-17'];
        yield 'the first of a month' => ['value' => '1815-12-01'];
        yield 'the 29th of February in a leap year' => ['value' => '2024-02-29'];
    }

    /**
     * The whole reason this validator exists.
     *
     * `createFromFormat()` does not fail on an out-of-range date: it returns an
     * object and reports the overflow as a *warning*, so `2026-02-30` silently
     * becomes 2026-03-02. A `false` check alone would accept it, and a regular
     * expression cannot tell — `\d{4}-\d{2}-\d{2}` matches a day that does not
     * exist.
     *
     * The first two assertions are the trap itself, so the third one is a
     * statement about this validator rather than about PHP.
     */
    #[Test]
    public function aDayThatDoesNotExistIsRejectedAlthoughItParses(): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!' . ProfileData::BIRTHDAY_FORMAT, '2026-02-30');

        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
        $this->assertSame('2026-03-02', $parsed->format('Y-m-d'));

        $this->assertSame([1786492305], $this->errorCodes(ProfileData::BIRTHDAY_FORMAT, '2026-02-30'));
    }

    #[Test]
    #[DataProvider('valuesThatAreNotADateInTheWireFormat')]
    public function aValueThatIsNotADateInTheFormatIsRejected(string $value): void
    {
        $this->assertSame([1786492305], $this->errorCodes(ProfileData::BIRTHDAY_FORMAT, $value));
    }

    /**
     * @return \Generator<string, array{value: string}>
     */
    public static function valuesThatAreNotADateInTheWireFormat(): \Generator
    {
        yield 'a month that does not exist' => ['value' => '2026-13-01'];
        yield 'the 29th of February in a common year' => ['value' => '2026-02-29'];
        yield 'the 31st of a 30 day month' => ['value' => '2026-04-31'];
        yield 'a relative expression' => ['value' => 'yesterday'];
        yield 'another notation' => ['value' => '17.05.1980'];
        yield 'an ATOM datetime' => ['value' => '1980-05-17T13:45:00+02:00'];
        yield 'a truncated date' => ['value' => '1980-05'];
        yield 'trailing content' => ['value' => '1980-05-17 13:45'];
        yield 'not a date at all' => ['value' => 'nonsense'];
    }

    /**
     * `$acceptsEmptyValues` stays at its inherited `true`, so an empty value
     * never reaches `isValid()`. An optional date is expressed by the absence
     * of a `NotEmptyValidator` in the rule set, not by a second opinion here.
     */
    #[Test]
    #[DataProvider('emptyValues')]
    public function anEmptyValueIsAccepted(mixed $value): void
    {
        $this->assertSame([], $this->errorCodes(ProfileData::BIRTHDAY_FORMAT, $value));
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
     * JSON has no date type, so the raw submitted value can be anything. A
     * non-string is a validation error with a code of its own rather than a
     * `TypeError` out of `createFromFormat()`.
     */
    #[Test]
    #[DataProvider('valuesOfAnotherType')]
    public function aValueOfAnotherTypeIsAValidationErrorRatherThanATypeError(mixed $value): void
    {
        $this->assertSame([1786492304], $this->errorCodes(ProfileData::BIRTHDAY_FORMAT, $value));
    }

    /**
     * @return \Generator<string, array{value: mixed}>
     */
    public static function valuesOfAnotherType(): \Generator
    {
        yield 'JSON number' => ['value' => 19800517];
        yield 'JSON float' => ['value' => 4.2];
        yield 'JSON true' => ['value' => true];
        yield 'JSON array' => ['value' => ['1980-05-17']];
        yield 'an already parsed date' => ['value' => new \DateTimeImmutable('1980-05-17')];
    }

    /**
     * The format is an option, and it is what decides: the same value passes
     * under one format and fails under another.
     */
    #[Test]
    public function theConfiguredFormatIsTheOneThatDecides(): void
    {
        $this->assertSame([], $this->errorCodes('d.m.Y', '10.12.1815'));
        $this->assertSame([1786492305], $this->errorCodes('d.m.Y', '1815-12-10'));
        $this->assertSame([1786492305], $this->errorCodes(ProfileData::BIRTHDAY_FORMAT, '10.12.1815'));
    }

    /**
     * A format that cannot be parsed with is a configuration error, not a
     * rejected value: reporting it as a validation error would tell the user
     * their input is wrong.
     */
    #[Test]
    #[DataProvider('formatOptionsThatAreNotUsable')]
    public function aFormatThatIsNotANonEmptyStringIsRejected(mixed $format): void
    {
        $validator = new DateStringValidator();
        $validator->setOptions(['format' => $format]);

        $this->expectException(InvalidValidationOptionsException::class);
        $this->expectExceptionCode(1786492303);

        $validator->validate('1980-05-17');
    }

    /**
     * @return \Generator<string, array{format: mixed}>
     */
    public static function formatOptionsThatAreNotUsable(): \Generator
    {
        yield 'the empty string' => ['format' => ''];
        yield 'a number' => ['format' => 42];
        yield 'null' => ['format' => null];
    }

    /**
     * @return list<int>
     */
    private function errorCodes(string $format, mixed $value): array
    {
        $validator = new DateStringValidator();
        $validator->setOptions(['format' => $format]);

        return array_values(array_map(
            static fn(Error $error): int => $error->getCode(),
            $validator->validate($value)->getErrors(),
        ));
    }
}
