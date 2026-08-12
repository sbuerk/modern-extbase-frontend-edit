<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractFunctionalTestCase;
use SBUERK\ModernExtbaseFrontendEdit\Validation\AddressRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\DtoValidator;
use SBUERK\ModernExtbaseFrontendEdit\Validation\EmailRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\ProfileImageUploadRules;
use SBUERK\ModernExtbaseFrontendEdit\Validation\ProfileRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\RuleSetInterface;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\ChoiceValidator;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Validator\DateStringValidator;
use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Every message a rejected save can render, and the guarantee that it renders
 * at all.
 *
 * A missing translation key is the quietest failure in this layer.
 * `LocalizationUtility::translate()` returns `null` for a key it cannot
 * resolve, `AbstractValidator::translateErrorMessage()` turns that into `''`,
 * and the endpoint then answers with an error that has a code, a property path
 * and no text — a form that highlights a field and says nothing. Nothing
 * throws, nothing is logged, and this repository has already shipped that bug
 * once.
 *
 * The keys are not listed here. They are collected from the rule sets and from
 * the two validators' own defaults, so a rule pointing at a key that was never
 * written fails this test on the day the rule is added rather than on the day a
 * user first violates it.
 */
final class ValidationMessageTest extends AbstractFunctionalTestCase
{
    /**
     * @param non-empty-string $translationKey
     */
    #[Test]
    #[DataProvider('everyConfiguredMessageKey')]
    public function everyConfiguredMessageKeyResolvesToText(string $translationKey): void
    {
        $translated = LocalizationUtility::translate($translationKey);

        $this->assertIsString(
            $translated,
            sprintf('"%s" does not resolve, so the rule using it would render an empty message.', $translationKey),
        );
        $this->assertNotSame('', $translated, sprintf('"%s" resolves to an empty message.', $translationKey));
    }

    /**
     * @return \Generator<string, array{translationKey: non-empty-string}>
     */
    public static function everyConfiguredMessageKey(): \Generator
    {
        foreach (self::collectMessageKeys() as $translationKey) {
            yield $translationKey => ['translationKey' => $translationKey];
        }
    }

    /**
     * A rule that configures no message at all falls back to the validator's
     * own default, which is a sentence written for a different context — or,
     * for a core validator, an untranslated English literal. Every rule
     * therefore has to name at least one of the message options its validator
     * declares in `$translationOptions`.
     */
    #[Test]
    #[DataProvider('ruleSets')]
    public function everyRuleConfiguresAMessage(RuleSetInterface $ruleSet): void
    {
        foreach ($ruleSet->rules() as $propertyName => $rules) {
            foreach ($rules as [$validatorClassName, $options]) {
                $this->assertNotSame(
                    [],
                    array_intersect(self::translationOptionsOf($validatorClassName), array_keys($options)),
                    sprintf(
                        'Rule "%s" of %s configures no message, so %s would answer with its own default.',
                        $propertyName,
                        $ruleSet::class,
                        $validatorClassName,
                    ),
                );
            }
        }
    }

    /**
     * The end of the same path: a real rejected value carries the sentence from
     * the XLIFF rather than a key or an empty string.
     */
    #[Test]
    public function aRejectedValueCarriesTheTranslatedMessage(): void
    {
        $error = (new DtoValidator())
            ->validate(new ProfileRuleSet(), new ProfileData(shortname: ''))
            ->forProperty('shortname')
            ->getFirstError();

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('Enter a short name.', $error->getMessage());
    }

    /**
     * The arguments of a message are positional and are filled in by
     * `translateErrorMessage()`, so the numbers in the sentence are the bounds
     * the rule was configured with and cannot drift away from them.
     */
    #[Test]
    public function aMessageWithArgumentsIsRenderedWithTheConfiguredBounds(): void
    {
        $error = (new DtoValidator())
            ->validate(new ProfileRuleSet(), new ProfileData(shortname: 'a'))
            ->forProperty('shortname')
            ->getFirstError();

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('The short name has to be between 2 and 255 characters long.', $error->getMessage());
        $this->assertSame([2, 255], $error->getArguments());
    }

    /**
     * The same guarantee for the upload rules, which no rule set covers.
     *
     * {@see ProfileImageUploadRules} deliberately does not implement
     * {@see RuleSetInterface} — its docblock says why — so the two tests above
     * cannot reach it, and the messages a rejected upload renders would be the
     * one part of this layer with no guarantee at all. Its keys are collected
     * with the others in `collectMessageKeys()`; what is left is the rule side.
     */
    #[Test]
    public function everyImageUploadRuleConfiguresAMessage(): void
    {
        foreach ((new ProfileImageUploadRules())->rules() as [$validatorClassName, $options]) {
            $this->assertNotSame(
                [],
                array_intersect(self::translationOptionsOf($validatorClassName), array_keys($options)),
                sprintf(
                    'The upload rule on %s configures no message, so it would answer with its own default.',
                    $validatorClassName,
                ),
            );
        }
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

    /**
     * Every `LLL:` key any rule configures, plus the defaults of the two
     * validators this extension adds — a rule set normally names a message of
     * its own, and these are what is shown when it does not.
     *
     * The upload rules are collected alongside the rule sets even though they
     * are not one: they carry the same kind of key, they fail the same silent
     * way, and the shape they share is exactly the part this method uses.
     *
     * @return list<non-empty-string>
     */
    private static function collectMessageKeys(): array
    {
        $keys = [];
        $ruleLists = [];
        foreach ([new ProfileRuleSet(), new AddressRuleSet(), new EmailRuleSet()] as $ruleSet) {
            foreach ($ruleSet->rules() as $rules) {
                $ruleLists[] = $rules;
            }
        }
        $ruleLists[] = (new ProfileImageUploadRules())->rules();

        foreach ($ruleLists as $rules) {
            foreach ($rules as [, $options]) {
                foreach ($options as $option) {
                    if (is_string($option) && str_starts_with($option, 'LLL:')) {
                        $keys[] = $option;
                    }
                }
            }
        }

        foreach ([ChoiceValidator::class, DateStringValidator::class] as $validatorClassName) {
            foreach (self::translationOptionsOf($validatorClassName) as $translationOption) {
                $default = (new \ReflectionClass($validatorClassName))->getDefaultProperties()[$translationOption] ?? null;
                if (is_string($default) && str_starts_with($default, 'LLL:')) {
                    $keys[] = $default;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The option keys a validator treats as messages, declared per validator in
     * its `$translationOptions`.
     *
     * @param class-string $validatorClassName
     * @return list<string>
     */
    private static function translationOptionsOf(string $validatorClassName): array
    {
        $translationOptions = (new \ReflectionClass($validatorClassName))->getDefaultProperties()['translationOptions'] ?? [];

        return is_array($translationOptions) ? array_values(array_filter($translationOptions, 'is_string')) : [];
    }
}
