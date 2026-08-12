<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The FAL fixture: a file storage, two indexed files, a real image file each,
 * and two profiles carrying one of them.
 *
 * It is a trait rather than another abstract test case because the tests that
 * need it sit in two different branches of the hierarchy — the plugin tests
 * below {@see \SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend\AbstractProfilePluginTestCase}
 * and the mapping test below {@see AbstractProfileTestCase} — and because the
 * upload tests will need exactly the same setup from a third place.
 *
 * ## What the fixture consists of
 *
 * - A `sys_file_storage` record, driver `Local`, base path `fileadmin/`. It is
 *   not optional and it is not decoration: a functional test instance has no
 *   storage record at all, and `ResourceFactory` cannot resolve a `sys_file`
 *   row whose `storage` column points at nothing.
 * - Two `sys_file` records, one **with** and one **without** a
 *   `sys_file_metadata` record. That difference is the whole reason there are
 *   two: `ProfileImage::dimension()` asks `hasProperty()` first, because the
 *   merged property set of a file without a metadata record does not contain
 *   `width` at all — a file whose metadata record merely holds `0` takes the
 *   other branch and leaves the guard uncovered.
 * - Two real image files, copied into `fileadmin/user_upload/` of the test
 *   instance, so the reference resolves to a file that exists on disk with
 *   dimensions that can be read back.
 * - Two profiles, uid {@see IMAGE_PROFILE_UID} and
 *   {@see IMAGE_UNMEASURED_PROFILE_UID}, both on the storage page. Every other
 *   fixture profile deliberately keeps no image: `getProfileImage()` returning
 *   `null` is documented behaviour of its own and needs a record to be observed
 *   on.
 *
 * ## Why the files are copied and not linked
 *
 * The testing framework offers both: `$pathsToLinkInTestInstance` symlinks a
 * path into the instance, `$pathsToProvideInTestInstance` copies it. Copying is
 * the only safe option here. `fileadmin/user_upload/` is the folder an upload
 * writes into, and behind a symlink a test that uploads, renames or deletes a
 * file would be writing into the repository working tree — a test suite that
 * dirties the checkout it runs from.
 *
 * Both properties are evaluated once per test **case**, when the instance is
 * created, not once per test. Files a test writes into `fileadmin/` therefore
 * survive into the next test of the same class, which is worth knowing for the
 * upload tests and does not affect the read only tests here.
 *
 * ## Why the image files are committed rather than generated
 *
 * They are 72 byte greyscale PNGs, which is small enough that a binary fixture
 * costs nothing, and being fixed bytes is what allows `sys_file.sha1` and
 * `sys_file.size` in `ProfileImages.csv` to state the truth about the file on
 * disk. Generating them would mean either writing the same bytes out as a PHP
 * string — a binary blob in source form, which is worse — or building them with
 * an image extension at runtime, which adds a dependency on the container image
 * and produces bytes that differ between library versions, so the two columns
 * could no longer be stated at all. `$pathsToProvideInTestInstance` needs a
 * source file inside the extension in any case.
 */
trait ProfileImageFixtureTrait
{
    /**
     * The profile carrying the image **with** a metadata record, and therefore
     * the only one for which `ProfileImage` resolves width and height.
     */
    protected const IMAGE_PROFILE_UID = 20;

    protected const IMAGE_PROFILE_NAME = 'Marie Curie';

    /**
     * The profile carrying the image **without** a metadata record: its
     * `ProfileImage` has a public URL and no dimensions.
     */
    protected const IMAGE_UNMEASURED_PROFILE_UID = 21;

    protected const IMAGE_UNMEASURED_PROFILE_NAME = 'Lise Meitner';

    /**
     * Uid of the `sys_file_reference` record of {@see IMAGE_PROFILE_UID}, which
     * is what `ProfileImage::$uid` carries.
     */
    protected const IMAGE_REFERENCE_UID = 1;

    /**
     * Uid of the `sys_file` record behind it, which is what
     * `ProfileImage::$fileUid` carries. Deliberately a different number from
     * the reference uid, so a mapping that confuses the two fails.
     */
    protected const IMAGE_FILE_UID = 1;

    protected const IMAGE_FILE_NAME = 'profile-image.png';

    /**
     * The public URL of the image as the **storage** builds it: relative to the
     * site root and without a leading slash, because the base path of the
     * storage is relative.
     *
     * This is what `ProfileImage::$publicUrl` carries outside of a frontend
     * request — a repository call, a JSON payload, a scheduler task.
     */
    protected const IMAGE_PUBLIC_URL = 'fileadmin/user_upload/profile-image.png';

    /**
     * The same URL as it appears in **rendered** frontend output.
     *
     * `PublicUrlPrefixer` is registered by the frontend `RequestHandler` and
     * prefixes every local resource URL with `config.absRefPrefix`, which
     * `FrontendTypoScriptFactory` defaults to `auto` when it is not set — the
     * site path, `/` here. The two constants therefore differ by exactly that
     * prefix, and stating both is deliberate: a test that asserted the storage
     * URL against rendered markup would silently be asserting a substring.
     */
    protected const IMAGE_RENDERED_URL = '/' . self::IMAGE_PUBLIC_URL;

    /**
     * Size in bytes and SHA1 of {@see IMAGE_FILE_NAME}, as stated by the
     * `sys_file` fixture row.
     */
    protected const IMAGE_FILE_SIZE = 72;

    protected const IMAGE_FILE_SHA1 = 'b81bd4485073182959a1ae82db944d841737c79a';

    /**
     * The dimensions of the `sys_file_metadata` record, which are the real
     * dimensions of the file on disk.
     */
    protected const IMAGE_WIDTH = 12;

    protected const IMAGE_HEIGHT = 8;

    /**
     * The title of the file **reference**, which is what the image partial
     * renders as the caption.
     */
    protected const IMAGE_REFERENCE_TITLE = 'Marie Curie in her laboratory';

    /**
     * The alternative text of the file reference of
     * {@see IMAGE_UNMEASURED_PROFILE_UID}.
     *
     * The reference of {@see IMAGE_PROFILE_UID} deliberately carries none, so
     * that the two branches of the `alt` attribute are covered by one fixture:
     * the reference text where there is one, the translated `profile.image.alt`
     * label where there is not.
     */
    protected const IMAGE_REFERENCE_ALTERNATIVE = 'Lise Meitner at the blackboard';

    /**
     * The image files, copied into the folder an upload would write into.
     *
     * The source path is relative to the test instance root, where the
     * extension is symlinked to `typo3conf/ext/<extension key>` before this is
     * evaluated.
     *
     * A constant rather than the `$pathsToProvideInTestInstance` property
     * itself: PHP rejects a trait property that is not identical to the one the
     * parent class declares, and `FunctionalTestCase` declares that one as an
     * empty array. Every class using this trait therefore assigns the constant:
     *
     * ```
     * protected array $pathsToProvideInTestInstance = self::PROFILE_IMAGE_FILES_TO_PROVIDE;
     * ```
     *
     * @var array<string, non-empty-string>
     */
    protected const PROFILE_IMAGE_FILES_TO_PROVIDE = [
        'typo3conf/ext/modern_extbase_frontend_edit/Tests/Functional/Fixtures/Files/' => 'fileadmin/user_upload/',
    ];

    /**
     * The folder an upload is stored in, relative to the instance root.
     *
     * The combined identifier of it is
     * {@see \SBUERK\ModernExtbaseFrontendEdit\Validation\ProfileImageUploadRules::DEFAULT_UPLOAD_FOLDER};
     * this is the same folder as a path, resolved through the base path of the
     * storage record above. It does not exist until the first upload creates
     * it — `createUploadFolderIfNotExist` is what creates it, and it only runs
     * once a file has passed validation.
     */
    protected const IMAGE_UPLOAD_FOLDER = 'fileadmin/user_upload/profiles/';

    /**
     * A second `sys_file_reference` pointing at {@see IMAGE_FILE_UID}, held by a
     * `tt_content` record this extension does not own.
     *
     * Imported per test rather than with the fixture above, because it is the
     * *difference* between the two cases of the cleanup guard: with it the
     * replaced file has to survive, without it the replaced file has to be
     * deleted. A fixture that were always present could only ever prove one of
     * the two.
     */
    protected const SHARED_FILE_REFERENCE_UID = 3;

    /**
     * Imports the storage, the two indexed files, the metadata record, the two
     * file references and the two profiles carrying them.
     *
     * Self-contained by design: it shares no uid with any other fixture of this
     * suite, so it can be imported next to `Profiles.csv` and next to
     * `ProfilePlugins.csv` alike.
     */
    protected function importProfileImageFixture(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Database/ProfileImages.csv');
    }

    /**
     * Adds {@see SHARED_FILE_REFERENCE_UID} — see there.
     */
    protected function importSharedFileReferenceFixture(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Database/ProfileImageSharedFile.csv');
    }

    /**
     * Adds a `sys_refindex` entry for {@see IMAGE_FILE_UID} that **no**
     * `sys_file_reference` row covers.
     *
     * A `t3://file` link in the rich text of a content element is the ordinary
     * way for that to happen, and it is the second, independent source the
     * cleanup guard consults: counting `sys_file_reference` alone would report
     * zero and delete a file an editor is linking to.
     */
    protected function importIndexedFileUsageFixture(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Database/ProfileImageIndexedUsage.csv');
    }

    /**
     * Puts the files of the test instance back into the state
     * `$pathsToProvideInTestInstance` produced, and removes everything an
     * upload wrote.
     *
     * This exists because the two are evaluated at different times.
     * `$pathsToProvideInTestInstance` is applied when the **instance** is
     * created, i.e. once per test case, while the database is restored before
     * every single test. A test that stores, replaces or deletes a file
     * therefore leaves the disk and the database disagreeing for every test
     * that follows it in the same class: the `sys_file` row of
     * {@see IMAGE_FILE_NAME} is back and the file it names is not.
     *
     * Calling this from `setUp()` closes that gap and, with it, the order
     * dependence between the tests of a class — which matters most for the
     * cleanup tests, whose whole subject is a file being deleted.
     */
    protected function resetProfileImageFiles(): void
    {
        $uploadFolder = $this->instancePath . '/' . self::IMAGE_UPLOAD_FOLDER;
        if (is_dir($uploadFolder)) {
            GeneralUtility::rmdir($uploadFolder, true);
        }

        foreach (self::PROFILE_IMAGE_FILES_TO_PROVIDE as $source => $target) {
            $sourcePath = $this->instancePath . '/' . $source;
            $targetPath = $this->instancePath . '/' . $target;
            GeneralUtility::mkdir_deep($targetPath);
            foreach (new \DirectoryIterator($sourcePath) as $file) {
                if ($file->isFile()) {
                    copy($file->getPathname(), $targetPath . $file->getFilename());
                }
            }
        }
    }

    /**
     * The names of the files an upload has stored, sorted, and `[]` while the
     * upload folder does not exist yet.
     *
     * Read off the disk rather than off `sys_file`, because the assertion it
     * serves — "a rejected upload moves nothing into storage" — is about the
     * file system. An indexed row without a file and a file without a row are
     * different defects, and only the second one is what
     * `FileHandlingService` could produce here.
     *
     * @return list<string>
     */
    protected function storedUploadFileNames(): array
    {
        $uploadFolder = $this->instancePath . '/' . self::IMAGE_UPLOAD_FOLDER;
        if (!is_dir($uploadFolder)) {
            return [];
        }

        $names = [];
        foreach (new \DirectoryIterator($uploadFolder) as $file) {
            if ($file->isFile()) {
                $names[] = $file->getFilename();
            }
        }
        sort($names);

        return $names;
    }
}
