<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Validation\Validator\FileSizeValidator;
use TYPO3\CMS\Extbase\Validation\Validator\ImageDimensionsValidator;
use TYPO3\CMS\Extbase\Validation\Validator\MimeTypeValidator;
use TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface;

/**
 * What the profile image endpoint accepts, expressed as data.
 *
 * ## Why this is not a {@see RuleSetInterface}
 *
 * It looks like one and deliberately is not. `RuleSetInterface` states as a
 * hard constraint that *every* validator it names must tolerate a `mixed`
 * value, because a partial save runs the leaf validators against whatever the
 * JSON payload happened to contain. The upload validators break that constraint
 * on purpose: `AbstractValidator::ensureFileUploadTypes()` throws
 * `\InvalidArgumentException` 1712057926 for anything that is not a
 * `\TYPO3\CMS\Core\Http\UploadedFile`
 * (`cms-extbase/Classes/Validation/Validator/AbstractValidator.php:197-213`).
 *
 * They are also consumed by a different machine. A rule set is handed to
 * {@see DtoValidator}, which builds a `GenericObjectValidator` around it; these
 * validators are handed to
 * `\TYPO3\CMS\Extbase\Mvc\Controller\FileUploadConfiguration::addValidator()`,
 * which Extbase runs itself in
 * `FileHandlingServiceConfiguration::getValidationResultsForProperty()`. Two
 * contracts that share a shape but not a meaning are better kept apart than
 * unified behind an interface that one of them violates.
 *
 * ## Two validators are enforced whether they are listed here or not
 *
 * `FileNameValidator` and `FileExtensionMimeTypeConsistencyValidator` are added
 * by core unless an instance of the same class is already configured
 * (`FileHandlingServiceConfiguration.php:252-264`). They are therefore not
 * repeated below — configuring them would only replace core's instance with an
 * identical one.
 *
 * A caveat that belongs with that, because it is visible in a response body:
 * `FileExtensionMimeTypeConsistencyValidator` renders the **detected MIME type
 * and the submitted file extension** into its message, and its message cannot
 * be overridden on v14.3. It declares
 * `protected array $translationOptions = ['inconsistentMessage']` while its
 * supported option and its property are both named `notAllowedMessage`, and
 * `AbstractValidator::initializeTranslationOptions()` only assigns an option
 * whose name matches an existing property (`:223-230`). Setting
 * `notAllowedMessage` on it is accepted and then silently ignored. Our own
 * messages below avoid placeholders that echo request data for exactly the
 * reason {@see \SBUERK\ModernExtbaseFrontendEdit\Http\JsonEnvelope} states;
 * core's do not, and that is not ours to fix here.
 *
 * ## Version neutrality
 *
 * The three validators below are identical on TYPO3 v13.4 and v14.3 — same
 * `$supportedOptions`, same error codes, same `final` classes; the files differ
 * only in the visibility of three private helpers. Nothing here needs a version
 * split.
 *
 * The class is data, not a service: `#[Exclude]`, `final readonly`, created
 * with `new` by whoever needs it.
 */
#[Exclude]
final readonly class ProfileImageUploadRules
{
    /**
     * The model property the upload is configured for.
     *
     * It is also the field name a rejected upload is keyed under in the `422`
     * body, because `FileHandlingServiceConfiguration` nests every upload error
     * under `forProperty($configuration->getPropertyName())` (`:194`, `:217`,
     * `:230`, `:241`). Client and server therefore agree on the string without
     * either of them declaring it twice.
     */
    public const PROPERTY = 'image';

    /**
     * The upload folder used when the site configures none.
     *
     * A combined storage identifier is mandatory —
     * `FileUploadConfiguration::ensureValidConfiguration()` throws 1711801071
     * for anything else — and the folder is created on first use, because
     * `createUploadFolderIfNotExist` defaults to `true`.
     */
    public const DEFAULT_UPLOAD_FOLDER = '1:/user_upload/profiles/';

    /**
     * The upper bound on the request body, as `FileSizeValidator` spells sizes.
     *
     * The format is a number followed by `B`, `K`, `M` or `G`; anything else
     * throws 1708595605 from the validator's own option check. This is not the
     * only limit that applies — `upload_max_filesize` and `post_max_size` cut
     * in before PHP ever builds `$_FILES`, and a request refused there never
     * reaches this endpoint at all. Keep this value at or below them, so that
     * the answer a user gets is ours and not the web server's.
     */
    public const MAXIMUM_FILE_SIZE = '5M';

    /**
     * The image formats a portrait may be uploaded in.
     *
     * SVG is excluded deliberately. It is an XML document that a browser
     * executes, `text/html` smuggled through an `image/svg+xml` MIME type is a
     * stored cross site scripting vector whenever the file is served from the
     * same origin, and nothing in this proof of concept needs vector portraits.
     * Adding it back is a security decision, not a convenience one.
     *
     * @var list<string>
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * The largest edge a portrait may have, in pixels.
     *
     * This is a resource limit, not a taste one: image processing allocates
     * roughly four bytes per pixel, so an unbounded edge is the cheapest way to
     * make thumbnail generation expensive.
     */
    private const MAXIMUM_EDGE = 5000;

    private const LL = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:';

    /**
     * Fresh validator instances, in the order they are evaluated.
     *
     * `FileHandlingServiceConfiguration` runs **all** of them over the uploaded
     * file and does not stop at the first failure (`:237-244`), so the order is
     * documentation rather than control flow. It is still the order the checks
     * belong in: `ImageDimensionsValidator` is meaningful only for a file that
     * is an image, and it says so itself by returning without an error when the
     * width or the height could not be determined.
     *
     * A fresh instance per call, never a cached one: a validator accumulates
     * its `Result` in `$this->result` and Extbase tags validators as
     * **non-shared** services for that reason
     * (`cms-extbase/Configuration/Services.php`), which is what
     * `GeneralUtility::makeInstance()` honours here.
     *
     * @return list<ValidatorInterface>
     */
    public function validators(): array
    {
        $validators = [];
        foreach ($this->rules() as [$validatorClassName, $options]) {
            $validator = GeneralUtility::makeInstance($validatorClassName);
            if (!$validator instanceof ValidatorInterface) {
                throw new \InvalidArgumentException(
                    sprintf('"%s" is not a %s.', $validatorClassName, ValidatorInterface::class),
                    1786496001
                );
            }
            // Unconditionally, as DtoValidator does and for the same reason:
            // setOptions() is what merges a validator's declared defaults into
            // $this->options, and isValid() reads them unguarded.
            $validator->setOptions($options);
            $validators[] = $validator;
        }

        return $validators;
    }

    /**
     * The rules themselves, as data.
     *
     * Every message is a fully qualified `LLL:EXT:…` key, which is the one form
     * whose semantics are identical on both core versions:
     * `AbstractValidator::translateErrorMessage()` translates anything starting
     * with `LLL:` and returns everything else verbatim, so no `$extensionName`
     * argument is needed — and that argument is precisely what changed between
     * v13 and v14.
     *
     * Which option keys count as messages is declared per validator in its
     * `$translationOptions`, and an option that is not listed there is accepted
     * and then ignored. The keys below are the listed ones.
     *
     * None of the messages carries a placeholder for a value the request
     * supplied. `MimeTypeValidator` passes the detected MIME type and the
     * submitted file extension as message arguments; a message without a
     * conversion specification simply drops them, which is what keeps the
     * response from echoing back what it just refused. The size and dimension
     * messages do carry `%1$s` — that argument is the bound *we* configured,
     * and telling a user the limit they exceeded is the entire point.
     *
     * @return list<array{0: class-string<ValidatorInterface>, 1: array<string, mixed>}>
     */
    public function rules(): array
    {
        return [
            [MimeTypeValidator::class, [
                'allowedMimeTypes' => self::ALLOWED_MIME_TYPES,
                'notAllowedMessage' => self::LL . 'validation.profile.image.mimeType',
                'invalidExtensionMessage' => self::LL . 'validation.profile.image.extension',
            ]],
            [FileSizeValidator::class, [
                'maximum' => self::MAXIMUM_FILE_SIZE,
                'exceedMessage' => self::LL . 'validation.profile.image.tooLarge',
            ]],
            // Upper bounds only. `minWidth` and `minHeight` are left at their
            // `0` default deliberately: a lower bound would be a statement about
            // what makes an acceptable portrait, which is a decision for the
            // site that adopts this template and not for the template. The
            // upper bounds are not that kind of rule — they are a resource
            // limit, and they belong here.
            [ImageDimensionsValidator::class, [
                'maxWidth' => self::MAXIMUM_EDGE,
                'maxHeight' => self::MAXIMUM_EDGE,
                'maxWidthMessage' => self::LL . 'validation.profile.image.tooWide',
                'maxHeightMessage' => self::LL . 'validation.profile.image.tooTall',
            ]],
        ];
    }
}
