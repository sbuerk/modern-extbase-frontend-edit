<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation;

use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\ChoiceValidator;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Extbase\Validation\Validator\EmailAddressValidator;
use TYPO3\CMS\Extbase\Validation\Validator\NotEmptyValidator;
use TYPO3\CMS\Extbase\Validation\Validator\StringLengthValidator;

/**
 * The rules of {@see EmailData}, and the whitelist of its writable fields.
 *
 * See {@see ProfileRuleSet} for why rules are data and which
 * `StringLengthValidator` message a given pair of bounds actually selects.
 */
#[Exclude]
final readonly class EmailRuleSet implements RuleSetInterface
{
    private const LL = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:';

    /**
     * The accepted values of the `type` column, mirroring the TCA select items
     * of `tx_modernextbasefrontendedit_domain_model_email.type` — see
     * {@see AddressRuleSet} for why they are repeated rather than read from
     * `$GLOBALS['TCA']`.
     *
     * @var list<string>
     */
    private const TYPES = ['private', 'business', 'others'];

    /**
     * @return array<non-empty-string, list<array{0: class-string<\TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface>, 1: array<string, mixed>}>>
     */
    public function rules(): array
    {
        return [
            'type' => [
                [ChoiceValidator::class, [
                    'choices' => self::TYPES,
                    'message' => self::LL . 'validation.email.type.invalid',
                ]],
            ],
            // Three rules, in the order their failures are useful. NotEmpty is
            // the only one that fires for an unsubmitted value, because it is
            // the only core leaf validator with $acceptsEmptyValues = false;
            // EmailAddressValidator and StringLengthValidator short-circuit on
            // '' and would otherwise report a missing address as malformed.
            'email' => [
                [NotEmptyValidator::class, [
                    'nullMessage' => self::LL . 'validation.email.email.empty',
                    'emptyMessage' => self::LL . 'validation.email.email.empty',
                ]],
                [EmailAddressValidator::class, [
                    'message' => self::LL . 'validation.email.email.invalid',
                ]],
                [StringLengthValidator::class, [
                    'maximum' => 255,
                    'exceedMessage' => self::LL . 'validation.email.email.tooLong',
                ]],
            ],
        ];
    }
}
