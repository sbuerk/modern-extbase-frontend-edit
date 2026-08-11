<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Model;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;

/**
 * The read side view on a {@see Profile} image.
 *
 * This value object is **derived and never stored**. It is not a persisted
 * property of `Profile`, it carries no `#[Transient]`, and it is not mapped by
 * Extbase at all: `Profile::getProfileImage()` builds it on demand from the
 * persisted `\TYPO3\CMS\Extbase\Domain\Model\FileReference`. A stored transient
 * property would need `#[Transient]`, and an attribute that is not written at
 * all cannot be written in a version specific way by accident.
 *
 * It exists so that templates, DTOs and JSON responses speak our vocabulary
 * instead of reaching through `getOriginalResource()->getOriginalFile()`. Only
 * scalars are exposed, so an instance is serializable and has no FAL object
 * graph behind it — which is also what makes it usable in a response payload
 * and testable without a storage.
 *
 * A value object is data, not a service: `#[Exclude]` keeps it out of the
 * dependency injection container, which would otherwise pick it up through the
 * resource loading in `Configuration/Services.php`.
 *
 * The write side deliberately has no counterpart here. Uploads are handled by
 * the Extbase upload API, which insists on the exact framework class — see
 * `docs/frontend-edit/image-handling.md`.
 */
#[Exclude]
final readonly class ProfileImage
{
    /**
     * @param int         $uid         Uid of the `sys_file_reference` record.
     * @param int         $fileUid     Uid of the referenced `sys_file` record.
     * @param string|null $publicUrl   `null` when the file is missing or deleted.
     * @param int|null    $width       `null` when the file carries no image dimensions.
     * @param int|null    $height      `null` when the file carries no image dimensions.
     */
    public function __construct(
        public int $uid,
        public int $fileUid,
        public ?string $publicUrl,
        public string $name,
        public string $extension,
        public string $mimeType,
        public int $size,
        public string $title,
        public string $alternative,
        public ?int $width,
        public ?int $height,
    ) {}

    /**
     * Builds the value object from the persisted Extbase file reference.
     *
     * `getOriginalResource()` resolves the FAL objects behind the reference,
     * lazily and through the `ResourceFactory`, so this is a read side
     * operation and expects a reference that exists in the database.
     */
    public static function fromFileReference(FileReference $fileReference): self
    {
        $resource = $fileReference->getOriginalResource();

        return new self(
            uid: $resource->getUid(),
            fileUid: $resource->getOriginalFile()->getUid(),
            publicUrl: $resource->getPublicUrl(),
            name: $resource->getName(),
            extension: $resource->getExtension(),
            mimeType: $resource->getMimeType(),
            size: $resource->getSize(),
            title: $resource->getTitle(),
            alternative: $resource->getAlternative(),
            // Dimensions come from the file metadata, which is empty for a file
            // that has no metadata record, and absent for a non image file.
            width: self::dimension($resource, 'width'),
            height: self::dimension($resource, 'height'),
        );
    }

    /**
     * @param non-empty-string $key
     */
    private static function dimension(\TYPO3\CMS\Core\Resource\FileReference $resource, string $key): ?int
    {
        if (!$resource->hasProperty($key)) {
            return null;
        }

        $value = $resource->getProperty($key);

        return is_numeric($value) ? (int)$value : null;
    }
}
