<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation\Validator;

use TYPO3\CMS\Extbase\Validation\Exception\InvalidValidationOptionsException;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

/**
 * Accepts a date **string** in a configured format, or an empty value.
 *
 * ## Why this is not a core validator
 *
 * `DateTimeValidator::isValid()` is a type check, not a parser:
 *
 * ```php
 * // typo3/cms-extbase/Classes/Validation/Validator/DateTimeValidator.php:38
 * if ($value instanceof \DateTimeInterface) {
 *     return;
 * }
 * ```
 *
 * It therefore rejects every value that can arrive in a JSON payload, because
 * JSON has no date type. A DTO that held a parsed `\DateTimeImmutable` would
 * not help: partial validation never builds a DTO, so the same rule would face
 * a string there and an object here.
 *
 * `RegularExpressionValidator` is no answer either. Beyond raising a
 * `TypeError` on a non-string value, a pattern can only check the *shape* of a
 * date — `2026-02-30` and `2026-13-01` match `\d{4}-\d{2}-\d{2}` and are not
 * dates. Only a parse can tell.
 *
 * ## Why the check is `createFromFormat()` plus `getLastErrors()`
 *
 * `\DateTimeImmutable::createFromFormat()` returns an object for an
 * out-of-range date and reports the overflow as a *warning* rather than
 * failing, so `2026-02-30` silently becomes 2026-03-02. Anything but a parse
 * with zero warnings and zero errors is rejected here.
 *
 * The format is prefixed with `!`, which resets every field it does not carry.
 * Without it a date-only format leaves the current time of day in the result,
 * which is the difference between "the day the client sent" and "that day at
 * whatever o'clock the request happened".
 *
 * ## Empty is valid
 *
 * `$acceptsEmptyValues` stays at its inherited `true`, so `null` and `''` never
 * reach {@see isValid()}. An optional date is expressed by the *absence* of a
 * `NotEmptyValidator` in the rule set, not by a second opinion here.
 *
 * ## Why it is not `#[Exclude]` and not `readonly`
 *
 * See {@see ChoiceValidator} — the same three reasons apply.
 */
final class DateStringValidator extends AbstractValidator
{
    protected string $message = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:validation.date.invalid';

    /**
     * @var array<string, array{0: mixed, 1: string, 2: string, 3?: bool}>
     */
    protected $supportedOptions = [
        'format' => ['Y-m-d', 'The expected format, as understood by \DateTimeImmutable::createFromFormat()', 'string'],
        'message' => [null, 'Translation key or message for a value that is not a date in that format', 'string'],
    ];

    public function isValid(mixed $value): void
    {
        $format = $this->options['format'];
        if (!is_string($format) || $format === '') {
            throw new InvalidValidationOptionsException(
                'The "format" option of the DateStringValidator must be a non-empty string.',
                1786492303
            );
        }

        if (!is_string($value)) {
            $this->addError($this->translateErrorMessage($this->message), 1786492304);
            return;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $parseErrors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($parseErrors !== false && ($parseErrors['error_count'] > 0 || $parseErrors['warning_count'] > 0))
        ) {
            $this->addError($this->translateErrorMessage($this->message), 1786492305);
        }
    }
}
