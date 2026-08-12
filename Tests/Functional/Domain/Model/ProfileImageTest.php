<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Domain\Model;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\ProfileImage;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\ProfileImageFixtureTrait;

/**
 * `ProfileImage` mapped from a **real** file reference.
 *
 * The unit test of the same name covers every branch of the mapping against a
 * mocked `\TYPO3\CMS\Core\Resource\FileReference`, which is the right shape for
 * a decision table and proves nothing about FAL. In particular it cannot show
 * that `hasProperty('width')` really is `false` for a file that has no metadata
 * record — that is an assumption the mock encodes rather than tests, and it is
 * the assumption the whole dimension handling rests on.
 *
 * So this test is deliberately the same mapping against a storage, an indexed
 * file, a file on disk and a persisted reference, resolved through the display
 * repository exactly as a plugin resolves it. What it adds over the unit test
 * is that the values on the left of every assertion are produced by TYPO3 and
 * not by a stub.
 *
 * Every lookup runs inside a frontend environment for the same reason every
 * other repository test in this suite does — see {@see AbstractProfileTestCase}.
 */
final class ProfileImageTest extends AbstractProfileTestCase
{
    use ProfileImageFixtureTrait;

    /**
     * A profile of `Profiles.csv` that carries no image at all.
     *
     * The FAL fixture only defines profiles that *have* one, so the "no image"
     * case comes from the domain fixture, which is also where every other test
     * of this directory takes its records from.
     */
    private const PROFILE_WITHOUT_IMAGE_UID = 1;

    protected array $pathsToProvideInTestInstance = self::PROFILE_IMAGE_FILES_TO_PROVIDE;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/Profiles.csv');
        $this->importProfileImageFixture();
    }

    /**
     * Every scalar the value object exposes, read off a real reference.
     *
     * `uid` and `fileUid` are two different numbers here on purpose — the
     * reference and the file it points at — because they are trivially
     * confusable and a fixture that gave them the same value would hide the
     * confusion.
     */
    #[Test]
    public function mapsEveryScalarOfARealFileReference(): void
    {
        $subject = $this->profileImageOf(self::IMAGE_PROFILE_UID);

        $this->assertSame(self::IMAGE_REFERENCE_UID, $subject->uid);
        $this->assertSame(self::IMAGE_FILE_UID, $subject->fileUid);
        $this->assertSame(self::IMAGE_PUBLIC_URL, $subject->publicUrl);
        $this->assertSame(self::IMAGE_FILE_NAME, $subject->name);
        $this->assertSame('png', $subject->extension);
        $this->assertSame('image/png', $subject->mimeType);
        $this->assertSame(self::IMAGE_FILE_SIZE, $subject->size);
        $this->assertSame(self::IMAGE_REFERENCE_TITLE, $subject->title);
        $this->assertSame('', $subject->alternative);
    }

    /**
     * The public URL points at a file that is really there.
     *
     * Asserted against the file system rather than against the string alone: a
     * URL is only worth anything if it resolves, and a `sys_file` fixture whose
     * `identifier` does not match the file that was copied into the instance
     * produces a perfectly well formed URL to a 404. The dimensions of the file
     * on disk are asserted in the same breath, because that is what makes the
     * metadata record below a statement about *this* image rather than two
     * numbers nobody checked.
     */
    #[Test]
    public function thePublicUrlResolvesToTheImageInTheTestInstance(): void
    {
        $subject = $this->profileImageOf(self::IMAGE_PROFILE_UID);
        $this->assertNotNull($subject->publicUrl);

        $absolutePath = $this->instancePath . '/' . ltrim($subject->publicUrl, '/');
        $this->assertFileExists($absolutePath);
        $this->assertSame(self::IMAGE_FILE_SIZE, filesize($absolutePath));
        $this->assertSame(self::IMAGE_FILE_SHA1, sha1_file($absolutePath));

        $dimensions = getimagesize($absolutePath);
        $this->assertIsArray($dimensions);
        $this->assertSame([self::IMAGE_WIDTH, self::IMAGE_HEIGHT], [$dimensions[0], $dimensions[1]]);
    }

    /**
     * The dimensions come from the `sys_file_metadata` record.
     */
    #[Test]
    public function readsTheDimensionsFromTheFileMetadata(): void
    {
        $subject = $this->profileImageOf(self::IMAGE_PROFILE_UID);

        $this->assertSame(self::IMAGE_WIDTH, $subject->width);
        $this->assertSame(self::IMAGE_HEIGHT, $subject->height);
    }

    /**
     * The `hasProperty()` guard of `ProfileImage::dimension()`, against a file
     * that genuinely has no metadata record.
     *
     * This is the assertion the unit test cannot make. A file without a
     * `sys_file_metadata` row does not merely carry a `width` of `0`, it
     * carries no `width` key at all — `File::getProperties()` merges in what
     * `MetaDataAspect::get()` found, and it found an empty array. Reaching for
     * `getProperty('width')` there raises `\InvalidArgumentException`, so
     * dropping the guard does not degrade the output, it breaks the page.
     */
    #[Test]
    public function reportsNoDimensionsForAFileWithoutAMetadataRecord(): void
    {
        $subject = $this->profileImageOf(self::IMAGE_UNMEASURED_PROFILE_UID);

        $this->assertNotNull($subject->publicUrl);
        $this->assertNull($subject->width);
        $this->assertNull($subject->height);
    }

    /**
     * The alternative text of the reference is the reference's own, not the
     * file's.
     */
    #[Test]
    public function mapsTheAlternativeTextOfTheFileReference(): void
    {
        $subject = $this->profileImageOf(self::IMAGE_UNMEASURED_PROFILE_UID);

        $this->assertSame(self::IMAGE_REFERENCE_ALTERNATIVE, $subject->alternative);
    }

    /**
     * A persisted profile whose `image` column is `0`.
     *
     * The unit test asserts this for a `Profile` that was never persisted,
     * where the property is simply still `null`. Here the object came out of
     * the data mapper, which is the layer that could hand back a
     * `FileReference` with an uid of `0` instead of nothing at all — and a
     * `ProfileImage` built from that resolves no file and blows up on the first
     * template that renders it.
     */
    #[Test]
    public function returnsNullForAPersistedProfileWithoutAnImage(): void
    {
        $this->assertNull($this->profileImageOrNullOf(self::PROFILE_WITHOUT_IMAGE_UID));
    }

    private function profileImageOf(int $profileUid): ProfileImage
    {
        $profileImage = $this->profileImageOrNullOf($profileUid);
        $this->assertInstanceOf(
            ProfileImage::class,
            $profileImage,
            sprintf('Profile %d carries an image.', $profileUid),
        );

        return $profileImage;
    }

    /**
     * Both the lookup and the FAL resolution happen inside the frontend
     * environment, and not only the lookup: `getProfileImage()` is what reaches
     * through to the storage, and the environment is what decides which enable
     * field constraints the metadata and reference queries carry.
     */
    private function profileImageOrNullOf(int $profileUid): ?ProfileImage
    {
        $profileImage = null;
        $this->executeInFrontendContext(function () use ($profileUid, &$profileImage): void {
            $profile = $this->get(ProfileRepository::class)->findByUid($profileUid);
            $this->assertInstanceOf(Profile::class, $profile, sprintf('Profile %d is found.', $profileUid));

            $profileImage = $profile->getProfileImage();
        });

        return $profileImage;
    }
}
