<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation;

use SBUERK\ModernExtbaseFrontendEdit\Validation\Exception\UnknownPropertyException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Error\Result;
use TYPO3\CMS\Extbase\Validation\Validator\GenericObjectValidator;
use TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface;

/**
 * Validates a data transfer object, or one submitted field of it, against a
 * {@see RuleSetInterface}.
 *
 * The edit endpoint has two shapes of request — a complete form submit and an
 * inline save of a single field — and they share one rule set through the two
 * entry points below. The service is stateless: it holds nothing, and every
 * call builds the validators it needs and throws them away again.
 *
 * ## `ValidatorResolver` is not used, and that is not a preference
 *
 * `ValidatorResolver::getBaseValidatorConjunction()` is the obvious way to
 * validate an arbitrary object and a trap for an endpoint that validates
 * repeatedly. All four reasons are in the installed v13.4 source:
 *
 * - It is `@internal` on the **class**, not merely on a method —
 *   `typo3/cms-extbase/Classes/Validation/ValidatorResolver.php:37`.
 * - The class is a `SingletonInterface`
 *   (`ValidatorResolver.php:39`) and caches the built conjunction per target
 *   class in `$this->baseValidatorConjunctions` (`ValidatorResolver.php:41`,
 *   filled and returned at `:88-96`), so every caller in a request shares one
 *   stateful `GenericObjectValidator`.
 * - `AbstractGenericObjectValidator::validate()` returns `$this->result`
 *   **unchanged** when the instance is already known:
 *
 *   ```php
 *   // typo3/cms-extbase/Classes/Validation/Validator/AbstractGenericObjectValidator.php:46-48
 *   if (is_object($value) && $this->isValidatedAlready($value)) {
 *       return $this->result;
 *   }
 *   ```
 *
 *   `$validatedInstancesContainer` (`:36`) is only ever added to, at `:151-154`,
 *   and nothing resets it. Validating, changing and re-validating the same DTO
 *   in one request therefore yields the **stale** `Result` of the first call.
 * - It recurses into every non-simple property type and throws `1363778104`
 *   outright when a property lacks a type declaration
 *   (`ValidatorResolver.php:135-138`).
 *
 * `ValidatorResolver::createValidator()` is skipped for the same reasons plus
 * one more: it calls `setRequest()` on the validator (`:68`), which is not part
 * of the v13 `ValidatorInterface` at all.
 *
 * A fresh `GenericObjectValidator` per call avoids all of it, costs nothing and
 * is public, non-`@internal` API that is identical in v13.4 and v14.3.
 *
 * ## Where the validators come from
 *
 * `GeneralUtility::makeInstance()`. Extbase tags every `ValidatorInterface`
 * implementation and runs `PublicServicePass('extbase.validator', true)` over
 * it — `typo3/cms-extbase/Configuration/Services.php:16-17` — which makes them
 * public **and non-shared**, so every fetch is a fresh instance and validator
 * state cannot bleed between calls. Outside a TYPO3 container `makeInstance()`
 * falls through to `new` (`GeneralUtility.php:2901-2906`), which is what keeps
 * this service usable in a plain unit test.
 */
final readonly class DtoValidator
{
    /**
     * Validates a fully hydrated data transfer object.
     *
     * Every rule of the rule set is added to a throwaway
     * `GenericObjectValidator`, which reads the property values through
     * `ObjectAccess` — so the DTOs' public promoted properties need no getters
     * — and nests each property's errors under its own name. The returned
     * `Result` is rooted at the object, so `getFlattenedErrors()` is keyed by
     * property path.
     *
     * A rule set naming a property the object does not expose is a programming
     * error and surfaces as the core `\RuntimeException` 1546632293 from
     * `AbstractGenericObjectValidator::getPropertyValue()`.
     */
    public function validate(RuleSetInterface $ruleSet, object $dto): Result
    {
        // Not `setOptions([])`: GenericObjectValidator declares no supported
        // options and reads none. Leaf validators are a different matter — see
        // createValidator().
        $objectValidator = GeneralUtility::makeInstance(GenericObjectValidator::class);
        foreach ($ruleSet->rules() as $propertyName => $rules) {
            foreach ($rules as [$validatorClassName, $options]) {
                $objectValidator->addPropertyValidator(
                    $propertyName,
                    $this->createValidator($validatorClassName, $options)
                );
            }
        }

        return $objectValidator->validate($dto);
    }

    /**
     * Validates one submitted field, addressed by name, against its own rules.
     *
     * The result is shaped exactly like the one {@see validate()} returns —
     * rooted at the object, with the errors under the property name — so a
     * client sees the same response structure for both kinds of save.
     *
     * ## Absent fields cannot produce an error, structurally
     *
     * Only the validators registered for `$propertyName` are built at all. The
     * alternative — validating a whole DTO and filtering the result — was
     * rejected for two reasons that this shape does not have:
     *
     * 1. Building a `final readonly` DTO from a single submitted field forces
     *    invented defaults for every other property, which are then validated
     *    and silently treated as submitted input.
     * 2. `NotEmptyValidator` is the only *core* leaf validator with
     *    `$acceptsEmptyValues = false`; a filter would have to special-case it
     *    to stop it firing on unsubmitted fields, and would go wrong again the
     *    moment a custom validator makes the same choice — which
     *    `Validation\Validator\ChoiceValidator` does.
     *
     * Not instantiating a validator is a guarantee. Discarding its output
     * afterwards is a convention the next contributor can break unknowingly.
     *
     * @param mixed $value The raw submitted value, exactly as it was decoded
     * @throws UnknownPropertyException if the rule set does not declare the field
     */
    public function validateProperty(RuleSetInterface $ruleSet, string $propertyName, mixed $value): Result
    {
        $rules = $ruleSet->rules();
        // The rule set is the single whitelist: it declares both what is
        // validated and which names a partial save may address. Rejecting here
        // keeps an unknown name from selecting validators or, later, reaching a
        // column, and it is a rejection rather than a no-op so that a renamed
        // field fails loudly instead of quietly saving nothing.
        if (!array_key_exists($propertyName, $rules)) {
            throw new UnknownPropertyException(
                sprintf(
                    'The field "%s" is not writable: %s declares no rules for it.',
                    $propertyName,
                    $ruleSet::class
                ),
                1786492306
            );
        }

        $result = new Result();
        foreach ($rules[$propertyName] as [$validatorClassName, $options]) {
            $validatorResult = $this->createValidator($validatorClassName, $options)->validate($value);
            if ($validatorResult->hasMessages()) {
                // Guarded, so that a valid value leaves no empty sub result
                // behind: forProperty() creates one on first access.
                $result->forProperty($propertyName)->merge($validatorResult);
            }
        }

        return $result;
    }

    /**
     * Builds one leaf validator and hands it its options.
     *
     * `setOptions()` is called unconditionally, including with an empty array.
     * It is what merges a validator's declared defaults into `$this->options`
     * (`AbstractValidator::initializeDefaultOptions()`), and skipping it leaves
     * that array empty — `StringLengthValidator::isValid()` then reads
     * `$this->options['maximum']` unguarded on the first line.
     *
     * @param class-string<ValidatorInterface> $validatorClassName
     * @param array<string, mixed> $options
     */
    private function createValidator(string $validatorClassName, array $options): ValidatorInterface
    {
        $validator = GeneralUtility::makeInstance($validatorClassName);
        if (!$validator instanceof ValidatorInterface) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a %s.', $validatorClassName, ValidatorInterface::class),
                1786492307
            );
        }
        $validator->setOptions($options);

        return $validator;
    }
}
