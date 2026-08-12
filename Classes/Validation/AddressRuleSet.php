<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation;

use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\ChoiceValidator;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Extbase\Validation\Validator\NotEmptyValidator;
use TYPO3\CMS\Extbase\Validation\Validator\StringLengthValidator;

/**
 * The rules of {@see AddressData}, and the whitelist of its writable fields.
 *
 * See {@see ProfileRuleSet} for why rules are data and which
 * `StringLengthValidator` message a given pair of bounds actually selects.
 */
#[Exclude]
final readonly class AddressRuleSet implements RuleSetInterface
{
    private const LL = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:';

    /**
     * The accepted values of the `type` column.
     *
     * These mirror the TCA select items of
     * `tx_modernextbasefrontendedit_domain_model_address.type`. They are
     * repeated rather than read from `$GLOBALS['TCA']` on purpose: a rule set
     * has to work without a TYPO3 bootstrap, and coupling validation to a
     * global that is assembled at runtime would make the rule untestable as a
     * unit and silently empty whenever the TCA is not loaded — which fails
     * open, accepting everything.
     *
     * @var list<string>
     */
    private const TYPES = ['home', 'work', 'others'];

    /**
     * @return array<non-empty-string, list<array{0: class-string<\TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface>, 1: array<string, mixed>}>>
     */
    public function rules(): array
    {
        return [
            // ChoiceValidator has $acceptsEmptyValues = false, so '' and null
            // are rejected by it and no separate NotEmptyValidator is needed.
            'type' => [
                [ChoiceValidator::class, [
                    'choices' => self::TYPES,
                    'message' => self::LL . 'validation.address.type.invalid',
                ]],
            ],
            'line1' => [
                [NotEmptyValidator::class, [
                    'nullMessage' => self::LL . 'validation.address.line1.empty',
                    'emptyMessage' => self::LL . 'validation.address.line1.empty',
                ]],
                [StringLengthValidator::class, [
                    'maximum' => 255,
                    'exceedMessage' => self::LL . 'validation.address.line1.tooLong',
                ]],
            ],
            'line2' => [
                [StringLengthValidator::class, [
                    'maximum' => 255,
                    'exceedMessage' => self::LL . 'validation.address.line2.tooLong',
                ]],
            ],
        ];
    }
}
