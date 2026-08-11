<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\ProfileImage;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference as CoreFileReference;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReference;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ProfileImageTest extends UnitTestCase
{
    /**
     * The value object exposes scalars and nothing else.
     *
     * That is what makes it usable in a Fluid template, a DTO and a JSON
     * response alike, and testable without a storage: no FAL object graph
     * hangs off it, so `getOriginalResource()->getOriginalFile()` never has to
     * be written anywhere but here.
     *
     * Note that `uid` is the uid of the `sys_file_reference` record while
     * `fileUid` is the uid of the referenced `sys_file` — two different
     * numbers that are easy to confuse and impossible to tell apart once
     * confused, which is why both are asserted with distinct values.
     */
    #[Test]
    public function fromFileReferenceExposesTheScalarsOfTheReference(): void
    {
        $subject = ProfileImage::fromFileReference($this->createFileReference());

        $this->assertSame(42, $subject->uid);
        $this->assertSame(7, $subject->fileUid);
        $this->assertSame('/fileadmin/user_upload/portrait.jpg', $subject->publicUrl);
        $this->assertSame('portrait.jpg', $subject->name);
        $this->assertSame('jpg', $subject->extension);
        $this->assertSame('image/jpeg', $subject->mimeType);
        $this->assertSame(4711, $subject->size);
        $this->assertSame('Portrait', $subject->title);
        $this->assertSame('A portrait of Jane Doe', $subject->alternative);
        $this->assertSame(800, $subject->width);
        $this->assertSame(600, $subject->height);
    }

    /**
     * A file whose storage cannot produce a URL yields `null`, not an empty
     * string. `FileReference::getPublicUrl()` returns `null` for a missing or
     * deleted file, and flattening that to `''` would turn "there is no URL"
     * into an `src=""` in the first template that forgets a guard.
     */
    #[Test]
    public function publicUrlIsNullWhenTheReferencedFileHasNone(): void
    {
        $subject = ProfileImage::fromFileReference($this->createFileReference(publicUrl: null));

        $this->assertNull($subject->publicUrl);
    }

    /**
     * The dimensions are the only mapping with a decision in it.
     *
     * They come from the file metadata, which is absent for a non-image file
     * and empty for an image that has no metadata record yet, so the property
     * may be missing altogether or present and unusable. Both cases become
     * `null` — a width of `0` would be a measurement, and there is none.
     */
    #[Test]
    #[DataProvider('dimensionMetadata')]
    public function dimensionsAreOnlyReportedWhenTheMetadataIsNumeric(
        bool $hasProperty,
        mixed $rawValue,
        ?int $expected,
    ): void {
        $subject = ProfileImage::fromFileReference($this->createFileReference(
            hasDimensions: $hasProperty,
            width: $rawValue,
            height: $rawValue,
        ));

        $this->assertSame($expected, $subject->width);
        $this->assertSame($expected, $subject->height);
    }

    /**
     * @return \Generator<string, array{hasProperty: bool, rawValue: mixed, expected: int|null}>
     */
    public static function dimensionMetadata(): \Generator
    {
        yield 'no metadata property at all, as for a non image file' => [
            'hasProperty' => false,
            'rawValue' => null,
            'expected' => null,
        ];
        yield 'metadata property present but empty' => [
            'hasProperty' => true,
            'rawValue' => '',
            'expected' => null,
        ];
        yield 'metadata property present but not numeric' => [
            'hasProperty' => true,
            'rawValue' => 'auto',
            'expected' => null,
        ];
        yield 'metadata property present and null' => [
            'hasProperty' => true,
            'rawValue' => null,
            'expected' => null,
        ];
        yield 'integer metadata' => [
            'hasProperty' => true,
            'rawValue' => 1024,
            'expected' => 1024,
        ];
        yield 'numeric string metadata, as read from the database' => [
            'hasProperty' => true,
            'rawValue' => '1024',
            'expected' => 1024,
        ];
    }

    private function createFileReference(
        ?string $publicUrl = '/fileadmin/user_upload/portrait.jpg',
        bool $hasDimensions = true,
        mixed $width = 800,
        mixed $height = 600,
    ): ExtbaseFileReference {
        $originalFile = $this->createMock(File::class);
        $originalFile->method('getUid')->willReturn(7);

        $resource = $this->createMock(CoreFileReference::class);
        $resource->method('getUid')->willReturn(42);
        $resource->method('getOriginalFile')->willReturn($originalFile);
        $resource->method('getPublicUrl')->willReturn($publicUrl);
        $resource->method('getName')->willReturn('portrait.jpg');
        $resource->method('getExtension')->willReturn('jpg');
        $resource->method('getMimeType')->willReturn('image/jpeg');
        $resource->method('getSize')->willReturn(4711);
        $resource->method('getTitle')->willReturn('Portrait');
        $resource->method('getAlternative')->willReturn('A portrait of Jane Doe');
        $resource->method('hasProperty')->willReturn($hasDimensions);
        $resource->method('getProperty')->willReturnMap([
            ['width', $width],
            ['height', $height],
        ]);

        $fileReference = $this->createMock(ExtbaseFileReference::class);
        $fileReference->method('getOriginalResource')->willReturn($resource);

        return $fileReference;
    }
}
