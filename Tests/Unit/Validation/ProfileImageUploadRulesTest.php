<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Validation\ProfileImageUploadRules;
use TYPO3\CMS\Extbase\Validation\Validator\FileSizeValidator;
use TYPO3\CMS\Extbase\Validation\Validator\ImageDimensionsValidator;
use TYPO3\CMS\Extbase\Validation\Validator\MimeTypeValidator;
use TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The two properties of the upload rules that a request cannot demonstrate.
 *
 * That the rules *work* is asserted where they are used:
 * `Tests/Functional/Frontend/ProfileImageUploadTest` uploads a file one byte
 * over the size bound and two images one pixel over the dimension bounds, and
 * asserts the error code of the rule that refused each of them. Nothing here
 * repeats that.
 *
 * What a single request cannot show is what happens across requests, and that
 * is what this class is for. A validator accumulates its `Result` in
 * `$this->result`, which is why Extbase tags validators as **non-shared**
 * services; {@see ProfileImageUploadRules::validators()} has to honour that by
 * building a new instance per call. A cached instance would answer the second
 * upload of a session with the errors of the first, and every functional test
 * of a single request would still be green.
 */
final class ProfileImageUploadRulesTest extends UnitTestCase
{
    /**
     * The rules build, in the documented order, with the bounds they name.
     *
     * The assertion that carries the most weight here is the one that is not
     * written down: `validators()` calls `setOptions()`, and
     * `AbstractValidator::initializeDefaultOptions()` throws
     * `InvalidValidationOptionsException` 1379981890 for an option key the
     * validator does not declare. Building the list therefore proves that every
     * option name in `rules()` is supported by the core version that is
     * installed — which is the failure this repository would otherwise first
     * see when a user uploads a file.
     */
    #[Test]
    public function everyRuleBuildsIntoAConfiguredValidatorOfTheInstalledCore(): void
    {
        $validators = (new ProfileImageUploadRules())->validators();

        $this->assertSame(
            [MimeTypeValidator::class, FileSizeValidator::class, ImageDimensionsValidator::class],
            array_map(static fn(ValidatorInterface $validator): string => $validator::class, $validators),
        );
        $this->assertSame(ProfileImageUploadRules::MAXIMUM_FILE_SIZE, $validators[1]->getOptions()['maximum']);
        $this->assertSame(5000, $validators[2]->getOptions()['maxWidth']);
        $this->assertSame(5000, $validators[2]->getOptions()['maxHeight']);
    }

    /**
     * Every call hands out validators nothing else holds — see the class
     * docblock for why that is the invariant worth pinning.
     */
    #[Test]
    public function everyCallReturnsFreshValidatorInstances(): void
    {
        $rules = new ProfileImageUploadRules();
        $first = $rules->validators();
        $second = $rules->validators();

        foreach ($first as $index => $validator) {
            $this->assertNotSame(
                $validator,
                $second[$index],
                sprintf('%s is built anew rather than shared between calls.', $validator::class),
            );
        }
    }
}
