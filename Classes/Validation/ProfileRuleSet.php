<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation;

use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\DateStringValidator;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Extbase\Validation\Validator\NotEmptyValidator;
use TYPO3\CMS\Extbase\Validation\Validator\StringLengthValidator;

/**
 * The rules of {@see ProfileData}, and the whitelist of its writable fields.
 *
 * Rules are data rather than `#[Validate]` attributes because that attribute
 * has no spelling that is valid on v13 and deprecation free on v14 — see
 * {@see RuleSetInterface}.
 *
 * ## Which `StringLengthValidator` message actually fires
 *
 * `StringLengthValidator` does not pick its message from the bound that was
 * violated. It picks it from which bounds are *configured*:
 *
 * ```php
 * // typo3/cms-extbase/Classes/Validation/Validator/StringLengthValidator.php:78-115
 * if ($this->options['minimum'] > 0 && $this->options['maximum'] < PHP_INT_MAX) { … betweenMessage … }
 * elseif ($this->options['minimum'] > 0) { … lessMessage … }
 * else { … exceedMessage … }
 * ```
 *
 * So a rule carrying both a minimum and a maximum only ever emits
 * `betweenMessage`, and configuring `lessMessage`/`exceedMessage` alongside it
 * is dead configuration. Each rule below therefore sets exactly the one message
 * option its bounds select, and the arguments of that message are positional:
 * `betweenMessage` receives minimum and maximum, `exceedMessage` the maximum.
 */
#[Exclude]
final readonly class ProfileRuleSet implements RuleSetInterface
{
    private const LL = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:';

    /**
     * The upper bound of the `varchar(255)` columns the input fields map to.
     *
     * `shortname`, `firstname` and `lastname` are `type => input` columns
     * without a `dbFieldLength`, which `DefaultTcaSchema` auto-creates as
     * `varchar(255)`. Submitting more would be truncated by the database rather
     * than rejected by us.
     */
    private const INPUT_COLUMN_LENGTH = 255;

    /**
     * @return array<non-empty-string, list<array{0: class-string<\TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface>, 1: array<string, mixed>}>>
     */
    public function rules(): array
    {
        return [
            // The only required field. It is the TCA 'label' of the table and
            // is marked 'required' there, so an empty one would produce a
            // record that cannot be identified in the backend either.
            'shortname' => [
                [NotEmptyValidator::class, [
                    'nullMessage' => self::LL . 'validation.profile.shortname.empty',
                    'emptyMessage' => self::LL . 'validation.profile.shortname.empty',
                ]],
                [StringLengthValidator::class, [
                    'minimum' => 2,
                    'maximum' => self::INPUT_COLUMN_LENGTH,
                    'betweenMessage' => self::LL . 'validation.profile.shortname.length',
                ]],
            ],
            'firstname' => [
                [StringLengthValidator::class, [
                    'maximum' => self::INPUT_COLUMN_LENGTH,
                    'exceedMessage' => self::LL . 'validation.profile.firstname.tooLong',
                ]],
            ],
            'lastname' => [
                [StringLengthValidator::class, [
                    'maximum' => self::INPUT_COLUMN_LENGTH,
                    'exceedMessage' => self::LL . 'validation.profile.lastname.tooLong',
                ]],
            ],
            // Optional: no NotEmptyValidator, and DateStringValidator inherits
            // $acceptsEmptyValues = true, so '' passes without a second rule
            // saying so. The format is the DTO's constant rather than a literal
            // here, so what this rule accepts cannot drift away from what
            // ProfileDataMapper parses.
            'birthday' => [
                [DateStringValidator::class, [
                    'format' => ProfileData::BIRTHDAY_FORMAT,
                    'message' => self::LL . 'validation.profile.birthday.invalid',
                ]],
            ],
            // The column is a nullable longtext, so this bound is a payload
            // sanity limit rather than a schema constraint. It exists because
            // an unbounded text field is the cheapest way to make an endpoint
            // expensive; pick a different number if the use case needs one, but
            // do not drop the rule.
            'bio' => [
                [StringLengthValidator::class, [
                    'maximum' => 5000,
                    'exceedMessage' => self::LL . 'validation.profile.bio.tooLong',
                ]],
            ],
        ];
    }
}
