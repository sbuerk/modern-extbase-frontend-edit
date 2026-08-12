<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation\Validator;

use TYPO3\CMS\Extbase\Validation\Exception\InvalidValidationOptionsException;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

/**
 * Accepts only values contained in a fixed list, compared strictly.
 *
 * ## Why this is not a core validator
 *
 * TYPO3 ships no "one of a fixed set" validator, and the only near match is
 * unusable at this boundary. `RegularExpressionValidator::isValid()` calls
 * `preg_match($this->options['regularExpression'], $value)` in a file declaring
 * `strict_types=1`, so a partial save submitting `{"type": 7}` raises a
 * `TypeError` — a 500 where the answer should have been a validation error.
 * Partial validation deliberately runs the leaf validators against the *raw*
 * submitted value, so every validator in a rule set has to tolerate `mixed`.
 *
 * A regex would also encode the accepted set twice, once as TCA select items
 * and once as an alternation, with nothing to keep the two in step.
 *
 * ## Why empty values are not accepted
 *
 * `$acceptsEmptyValues` is `false`, which is what makes `null` and `''` reach
 * {@see isValid()} at all — `AbstractValidator::validate()` skips `isValid()`
 * for empty values otherwise. For a select that is the only correct answer:
 * the empty string is not one of the choices unless it is listed as one, and
 * accepting it would let a partial save write `''` into a column whose TCA
 * pins a non-empty default.
 *
 * ## Why it is not `#[Exclude]` and not `readonly`
 *
 * A validator is not a data object. Extbase autoconfigures every
 * `ValidatorInterface` implementation into the container and runs
 * `PublicServicePass('extbase.validator', true)` over it — see
 * `typo3/cms-extbase/Configuration/Services.php:16-17` — which makes it public
 * **and non-shared**, so every `GeneralUtility::makeInstance()` yields a fresh
 * instance and constructor dependencies would work. `#[Exclude]` would remove
 * it from the container and quietly break that. `readonly` is impossible
 * because `AbstractValidator` writes `$this->result` and `$this->options` on
 * every call.
 *
 * It extends `AbstractValidator` rather than implementing `ValidatorInterface`
 * directly: v14 added `setRequest()`/`getRequest()` to the interface (Breaking
 * #106056), which v13 does not know, and the abstract class covers both.
 */
final class ChoiceValidator extends AbstractValidator
{
    /**
     * Always executed, even for `null` and `''` — see the class docblock.
     *
     * Declared untyped to match `AbstractValidator::$acceptsEmptyValues`, which
     * is an untyped `protected $acceptsEmptyValues = true` in both v13.4 and
     * v14.3. A typed redeclaration would be a fatal error.
     *
     * @var bool
     */
    protected $acceptsEmptyValues = false;

    protected string $message = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:validation.choice.invalid';

    /**
     * @var array<string, array{0: mixed, 1: string, 2: string, 3?: bool}>
     */
    protected $supportedOptions = [
        'choices' => [[], 'The accepted values, compared with strict equality', 'array', true],
        'message' => [null, 'Translation key or message for a value outside the accepted set', 'string'],
    ];

    public function isValid(mixed $value): void
    {
        $choices = $this->options['choices'];
        if (!is_array($choices)) {
            throw new InvalidValidationOptionsException(
                'The "choices" option of the ChoiceValidator must be an array.',
                1786492301
            );
        }

        if (!in_array($value, $choices, true)) {
            $this->addError($this->translateErrorMessage($this->message), 1786492302);
        }
    }
}
