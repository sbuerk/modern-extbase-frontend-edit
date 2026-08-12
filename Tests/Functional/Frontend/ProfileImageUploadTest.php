<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The image endpoints, exercised by request: upload, replacement, removal, and
 * the guard that decides whether the replaced file may be deleted.
 *
 * ## The one test this class exists for
 *
 * {@see aFileAnotherRecordReferencesSurvivesAReplacement()}. Deleting a
 * `sys_file` hard-deletes **every** `sys_file_reference` row pointing at it, in
 * every table, from an event listener a caller cannot opt out of
 * (`FileDeletionAspect::removeFromRepository()`). An unguarded cleanup after a
 * replacement therefore does not waste disk — it silently removes the image
 * from records this extension does not own. That test asserts both halves: the
 * `sys_file` row survives *and* the foreign reference row is byte identical
 * afterwards.
 *
 * Its mirror, {@see aFileNothingElseReferencesIsDeletedAfterAReplacement()}, is
 * what keeps the guard from being satisfied by never deleting anything.
 *
 * ## Why the file state is reset per test and the database is not
 *
 * The database is restored before every test by the testing framework;
 * `$pathsToProvideInTestInstance` is applied once per test **case**, when the
 * instance is created. A test that deletes a file therefore leaves the next
 * test of the same class with a `sys_file` row whose file is gone.
 * `AbstractProfileAjaxTestCase::setUp()` calls
 * `ProfileImageFixtureTrait::resetProfileImageFiles()` for that reason, and it
 * is what makes the two cleanup tests independent of the order they run in.
 *
 * ## What the fixture provides
 *
 * `ProfileImages.csv` — imported for every plugin test — carries the storage,
 * two indexed files and two profiles of the owner that reference them.
 * {@see \SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\ProfileImageFixtureTrait::IMAGE_PROFILE_UID}
 * is the profile a replacement is tested on;
 * {@see AbstractProfileAjaxTestCase::OWNED_PROFILE_UID} carries no image at all
 * and is therefore the first-upload case.
 */
final class ProfileImageUploadTest extends AbstractProfileAjaxTestCase
{
    /**
     * The `sys_file` uid a first upload creates.
     *
     * The fixture occupies 1 and 2, so the first row an upload inserts is 3.
     * Stated rather than searched for: a test that looked for "the highest uid"
     * would report the same number for an implementation that inserted two rows.
     */
    private const FIRST_UPLOADED_FILE_UID = 3;

    #[Test]
    public function anUploadStoresTheFileAndAnswersWithTheNewImage(): void
    {
        $response = $this->sendUploadRequest(
            uid: self::OWNED_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());

        $image = $this->imageOf($response);
        $this->assertSame(self::FIRST_UPLOADED_FILE_UID, $image['fileUid']);
        $this->assertSame('image/png', $image['mimeType']);
        $this->assertSame('png', $image['extension']);
        $this->assertSame(strlen($this->fixtureImageBytes()), $image['size']);

        // The stored name is the *client* name plus the random suffix
        // `addRandomSuffix` appends — not the name of the temporary file, and
        // not the client name unchanged.
        $this->assertIsString($image['name']);
        $this->assertMatchesRegularExpression('/^portrait-[0-9a-f]{16}\.png$/', $image['name']);
        $this->assertSame([$image['name']], $this->storedUploadFileNames());

        $reference = $this->rawRow(self::FILE_REFERENCE_TABLE, $image['uid']);
        $this->assertSame(self::FIRST_UPLOADED_FILE_UID, (int)$reference['uid_local']);
        $this->assertSame(self::OWNED_PROFILE_UID, (int)$reference['uid_foreign']);
        $this->assertSame(self::PROFILE_TABLE, $reference['tablenames']);
        $this->assertSame('image', $reference['fieldname']);
        $this->assertSame(0, (int)$reference['deleted']);

        // The **reference uid**, not the relation count a `type => 'file'`
        // column carries when the DataHandler wrote it: for a property holding
        // a domain object, `Backend::persistObject()` writes
        // `getPlainValue($propertyValue)`, which is that object's uid
        // (`cms-extbase/Classes/Persistence/Generic/Backend.php:290-296`).
        // Nothing reads the column back — the relation is resolved through
        // `foreign_field`/`foreign_table_field` on `sys_file_reference` on both
        // the Extbase and the TCA side — so this is asserted as the behaviour
        // it is rather than as the behaviour it looks like.
        $this->assertSame(
            $image['uid'],
            (int)$this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID)['image'],
            'The profile row points at the reference that was created.',
        );
    }

    /**
     * The URL the response reports points at the file that was uploaded.
     *
     * The URL is the one a browser would request, i.e. the storage URL after
     * `PublicUrlPrefixer` prefixed it with `config.absRefPrefix` — `/` for this
     * site. It is resolved against the document root of the instance, because
     * that is what serves it: a functional sub-request is answered by the TYPO3
     * application, which routes `/fileadmin/…` as a page path and would answer
     * `404` for a file the web server delivers without ever reaching PHP.
     *
     * Resolving it is the point. A `sys_file` row whose `identifier` disagrees
     * with the file that was moved produces a perfectly well formed URL to
     * nothing.
     */
    #[Test]
    public function theReportedUrlResolvesToTheUploadedFile(): void
    {
        $response = $this->sendUploadRequest(
            uid: self::OWNED_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $image = $this->imageOf($response);
        $this->assertIsString($image['publicUrl']);
        $this->assertStringStartsWith('/' . self::IMAGE_UPLOAD_FOLDER, $image['publicUrl']);
        $this->assertStringEndsWith($image['name'], $image['publicUrl']);

        $absolutePath = $this->instancePath . '/' . ltrim($image['publicUrl'], '/');
        $this->assertFileExists($absolutePath);
        $this->assertSame($this->fixtureImageBytes(), file_get_contents($absolutePath));
    }

    /**
     * A replacement repoints the existing reference row instead of creating a
     * second one, and the row survives the cleanup that follows.
     *
     * The second half is rule 2 of the contract: the cleanup runs **after**
     * `persistAll()`. Before the flush the row still carries the old
     * `uid_local`, and deleting the file at that moment would take out the very
     * row that is about to be repointed — `FileDeletionAspect` deletes every
     * reference of a deleted file.
     */
    #[Test]
    public function aReplacementRepointsTheExistingReferenceAtTheNewFile(): void
    {
        $before = $this->rawRow(self::FILE_REFERENCE_TABLE, self::IMAGE_REFERENCE_UID);
        $this->assertSame(self::IMAGE_FILE_UID, (int)$before['uid_local']);

        $response = $this->sendUploadRequest(
            uid: self::IMAGE_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());

        $image = $this->imageOf($response);
        $this->assertSame(
            self::IMAGE_REFERENCE_UID,
            $image['uid'],
            'The response reports the reference that already existed, not a synthetic uid.',
        );
        $this->assertNotSame(self::IMAGE_FILE_UID, $image['fileUid'], 'The image points at the new file.');

        $after = $this->rawRow(self::FILE_REFERENCE_TABLE, self::IMAGE_REFERENCE_UID);
        $this->assertSame(0, (int)$after['deleted'], 'The reference row survived the cleanup.');
        $this->assertSame($image['fileUid'], (int)$after['uid_local']);
        $this->assertSame(
            [self::IMAGE_REFERENCE_UID],
            $this->referenceUidsOfProfile(self::IMAGE_PROFILE_UID),
            'No second reference row was created for the same profile.',
        );
    }

    /**
     * A file a record of another table still references is **not** deleted when
     * the profile replaces it.
     *
     * Both halves are the assertion. The `sys_file` row surviving is what the
     * guard decides; the foreign `sys_file_reference` row surviving is what the
     * guard exists for, and it is the one that is destroyed when the guard is
     * removed — by an event listener the deleting code never calls itself.
     */
    #[Test]
    public function aFileAnotherRecordReferencesSurvivesAReplacement(): void
    {
        $this->importSharedFileReferenceFixture();
        $sharedBefore = $this->rawRow(self::FILE_REFERENCE_TABLE, self::SHARED_FILE_REFERENCE_UID);
        $fileBefore = $this->rawRow(self::FILE_TABLE, self::IMAGE_FILE_UID);

        $response = $this->sendUploadRequest(
            uid: self::IMAGE_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        // Asserted first, deliberately: this is the row that is destroyed when
        // the guard is removed, and it is the damage that leaves the site with
        // a content element whose image vanished.
        $this->assertSame(
            [$sharedBefore],
            $this->rowsOf(self::FILE_REFERENCE_TABLE, self::SHARED_FILE_REFERENCE_UID),
            'The reference held by the other record is untouched.',
        );
        $this->assertSame(
            [$fileBefore],
            $this->rowsOf(self::FILE_TABLE, self::IMAGE_FILE_UID),
            'The replaced file is still indexed, because something else still references it.',
        );
        $this->assertFileExists(
            $this->instancePath . '/' . self::IMAGE_PUBLIC_URL,
            'The file of the other record is still on disk.',
        );
    }

    /**
     * A file that only the **reference index** still knows about survives too.
     *
     * The guard is asymmetric on purpose and consults two sources, of which
     * either keeps the file. This is the second one: a `t3://file` link in the
     * rich text of a content element is a usage that no `sys_file_reference`
     * row covers at all, so a guard built on the reference table alone would
     * count zero and delete a file an editor is linking to.
     */
    #[Test]
    public function aFileTheReferenceIndexStillKnowsAboutSurvivesAReplacement(): void
    {
        $this->importIndexedFileUsageFixture();
        $fileBefore = $this->rawRow(self::FILE_TABLE, self::IMAGE_FILE_UID);

        $response = $this->sendUploadRequest(
            uid: self::IMAGE_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [$fileBefore],
            $this->rowsOf(self::FILE_TABLE, self::IMAGE_FILE_UID),
            'The replaced file is kept, because the reference index still records a usage.',
        );
        $this->assertFileExists($this->instancePath . '/' . self::IMAGE_PUBLIC_URL);
    }

    /**
     * The mirror case: a file only this profile referenced is deleted, so
     * replacements do not accumulate.
     *
     * Without it the guard above would be satisfied by an implementation that
     * never deletes anything at all.
     */
    #[Test]
    public function aFileNothingElseReferencesIsDeletedAfterAReplacement(): void
    {
        $response = $this->sendUploadRequest(
            uid: self::IMAGE_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [],
            $this->rowsOf(self::FILE_TABLE, self::IMAGE_FILE_UID),
            'The replaced file is no longer indexed.',
        );
        // The storage of this instance has no `_recycler_` folder, so
        // `ResourceStorage::deleteFile()` unlinks rather than moves. With one
        // the row would be gone and the file would not — which is why the
        // service documents "the physical file is deleted" as storage dependent.
        $this->assertFileDoesNotExist($this->instancePath . '/' . self::IMAGE_PUBLIC_URL);
    }

    /**
     * Upload, replace, remove: afterwards the profile has no image anywhere.
     *
     * The removal is asserted three times over, because the three can disagree:
     * the response document, the raw rows, and the rendered `show` plugin —
     * which is the page a visitor gets and the only one of the three that proves
     * the read side agrees with the write side.
     */
    #[Test]
    public function anImageCanBeReplacedAndThenRemovedEverywhere(): void
    {
        $replaced = $this->sendUploadRequest(
            uid: self::IMAGE_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );
        $this->assertSame(200, $replaced->getStatusCode());
        $uploaded = $this->imageOf($replaced);
        $this->assertIsString($uploaded['name']);

        $removed = $this->sendAjaxRequest(
            action: 'removeImage',
            payload: ['uid' => self::IMAGE_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $removed->getStatusCode());
        $this->assertNull($this->successData($removed)['image'], 'The document carries no image.');

        $this->assertSame(
            0,
            (int)$this->rawRow(self::PROFILE_TABLE, self::IMAGE_PROFILE_UID)['image'],
            'The relation count of the profile row is back to zero.',
        );
        $this->assertSame(
            [],
            $this->referenceUidsOfProfile(self::IMAGE_PROFILE_UID),
            'The profile holds no live image reference any more.',
        );
        $this->assertSame(
            [],
            $this->rowsOf(self::FILE_TABLE, $uploaded['fileUid']),
            'The file the profile had is deleted, because nothing else referenced it.',
        );
        // Gone rather than soft deleted, and the order is why: the endpoint
        // soft deletes the reference row first and the file afterwards, and
        // deleting a `sys_file` hard-deletes every `sys_file_reference` row
        // pointing at it — its own included. The soft delete is not pointless,
        // it is what makes the cleanup service count zero references and reach
        // the deletion at all; the row surviving is what happens when the file
        // is kept, which is the case above.
        $this->assertSame(
            [],
            $this->rowsOf(self::FILE_REFERENCE_TABLE, self::IMAGE_REFERENCE_UID),
            'The reference row went with the file it pointed at.',
        );
        $this->assertSame([], $this->storedUploadFileNames(), 'Nothing is left in the upload folder.');

        $rendered = (string)$this->renderShowPlugin(self::IMAGE_PROFILE_UID)->getBody();
        $this->assertStringNotContainsString('<figure class="modern-extbase-frontend-edit-profile-image">', $rendered);
        $this->assertStringNotContainsString($uploaded['name'], $rendered);
        $this->assertStringContainsString(self::IMAGE_PROFILE_NAME, $rendered, 'The profile itself still renders.');
    }

    /**
     * Removing an image a profile does not have is answered, not refused.
     *
     * A client that removes twice — a double click, a retry after a timeout —
     * reaches the same state either way, and an error about a state the caller
     * already wanted would have to be swallowed by every one of them.
     */
    #[Test]
    public function removingAnAbsentImageIsAnsweredWithTheUnchangedDocument(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'removeImage',
            payload: ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->successData($response)['image']);
    }

    /**
     * Files the endpoint has to refuse, and the reason each of them is refused.
     *
     * @return \Generator<string, array{contents: string|null, clientFileName: string, clientMediaType: string}>
     */
    public static function rejectedUploads(): \Generator
    {
        yield 'a media type that is not an allowed image' => [
            'contents' => 'This is not an image at all.',
            'clientFileName' => 'notes.txt',
            'clientMediaType' => 'text/plain',
        ];
        yield 'an image whose extension is not an image extension' => [
            'contents' => null,
            'clientFileName' => 'portrait.php',
            'clientMediaType' => 'image/png',
        ];
        yield 'a file extension that contradicts the content' => [
            'contents' => 'This is not an image at all.',
            'clientFileName' => 'portrait.png',
            'clientMediaType' => 'image/png',
        ];
    }

    /**
     * A rejected upload answers `422` keyed by the field name, and moves nothing
     * into storage.
     *
     * The second half is asserted on the **folder**, not on the response: the
     * validators run before the mapping, so a file that reached the storage
     * anyway would still produce a `422` and a test reading only the status
     * would call that correct. It is also the property the surface tells the
     * user about — the file has to be picked again — so it has to be true.
     */
    #[DataProvider('rejectedUploads')]
    #[Test]
    public function aRejectedUploadIsRefusedAndStoresNothing(
        ?string $contents,
        string $clientFileName,
        string $clientMediaType,
    ): void {
        $snapshot = $this->recordSnapshot();
        $stored = $this->storedUploadFileNames();

        $response = $this->sendUploadRequest(
            uid: self::OWNED_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            clientFileName: $clientFileName,
            contents: $contents,
            clientMediaType: $clientMediaType,
        );

        // The storage is asserted **before** the status, because the storage is
        // what this test is about: a rejected upload that moved the file anyway
        // answers `422` just as convincingly, and a test that read the status
        // first would report the wrong thing when that is what broke.
        $this->assertSame($stored, $this->storedUploadFileNames(), 'The upload folder gained no file.');
        $this->assertSame($snapshot, $this->recordSnapshot(), 'Nothing was indexed and nothing was referenced.');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['image'],
            array_values(array_unique($this->errorFields($response))),
            'Every message is keyed by the property name the client knows the control as.',
        );
    }

    /**
     * An upload aimed at a profile of another user answers the uniform `404`,
     * changes nothing, and stores nothing.
     *
     * The last part is what makes this different from the JSON endpoints: an
     * upload carries a file, and a guard that refused *after* the mapping would
     * still answer `404` while having written a file into the storage of the
     * site.
     */
    #[Test]
    public function anUploadForAForeignProfileIsRefusedAndStoresNothing(): void
    {
        $snapshot = $this->recordSnapshot();
        $foreignBefore = $this->rawRow(self::PROFILE_TABLE, self::FOREIGN_PROFILE_UID);

        $response = $this->sendUploadRequest(
            uid: self::FOREIGN_PROFILE_UID,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        // The effects first, the status afterwards — see
        // {@see aRejectedUploadIsRefusedAndStoresNothing()} for why. A guard
        // that answers `404` after it has written is the failure this test
        // exists to name, and it has to be the failure that is reported.
        $this->assertSame(
            $foreignBefore,
            $this->rawRow(self::PROFILE_TABLE, self::FOREIGN_PROFILE_UID),
            'The addressed record of the other user is untouched.',
        );
        $this->assertSame([], $this->storedUploadFileNames(), 'No file reached the storage.');
        $this->assertSame($snapshot, $this->recordSnapshot(), 'No record was written.');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([1786495924], $this->errorCodes($response));
    }

    /**
     * The transport of the upload endpoint, which is the one thing about it that
     * differs from every other endpoint of this controller.
     *
     * @return \Generator<string, array{arguments: array<string, mixed>, expectedStatus: int, expectedCode: int}>
     */
    public static function refusedUploadTransports(): \Generator
    {
        yield 'a GET request' => [
            'arguments' => ['method' => 'GET'],
            'expectedStatus' => 405,
            'expectedCode' => 1786496002,
        ];
        yield 'a JSON body' => [
            'arguments' => ['contentType' => 'application/json'],
            'expectedStatus' => 400,
            'expectedCode' => 1786496003,
        ];
        yield 'no content type at all' => [
            'arguments' => ['contentType' => null],
            'expectedStatus' => 400,
            'expectedCode' => 1786496003,
        ];
        yield 'no request token' => [
            'arguments' => ['requestToken' => self::TOKEN_ABSENT],
            'expectedStatus' => 403,
            'expectedCode' => 1786495903,
        ];
        yield 'a valid token of another scope' => [
            'arguments' => ['requestToken' => self::TOKEN_FOREIGN_SCOPE],
            'expectedStatus' => 403,
            'expectedCode' => 1786495903,
        ];
        yield 'no uid' => [
            'arguments' => ['uid' => null],
            'expectedStatus' => 400,
            'expectedCode' => 1786496004,
        ];
        yield 'a uid that is not a decimal integer' => [
            'arguments' => ['rawUid' => ' 01 '],
            'expectedStatus' => 400,
            'expectedCode' => 1786496005,
        ];
        yield 'more than one file' => [
            'arguments' => ['fileCount' => 2],
            'expectedStatus' => 400,
            'expectedCode' => 1786496006,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('refusedUploadTransports')]
    #[Test]
    public function aMalformedUploadIsRefusedAndStoresNothing(
        array $arguments,
        int $expectedStatus,
        int $expectedCode,
    ): void {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendUploadRequest(...array_replace(
            ['uid' => self::OWNED_PROFILE_UID, 'frontendUserId' => self::OWNER_FRONTEND_USER_ID],
            $arguments,
        ));

        $this->assertSame($expectedStatus, $response->getStatusCode());
        $this->assertSame([$expectedCode], $this->errorCodes($response));
        $this->assertSame([], $this->storedUploadFileNames(), 'No file reached the storage.');
        $this->assertSame($snapshot, $this->recordSnapshot(), 'No record was written.');
    }

    /**
     * The `image` object of a success envelope, asserting on the way that there
     * is one.
     *
     * @return array<string, mixed>
     */
    private function imageOf(ResponseInterface $response): array
    {
        $data = $this->successData($response);
        $this->assertArrayHasKey('image', $data);
        $this->assertIsArray($data['image'], 'The response describes the stored image.');

        /** @var array<string, mixed> $image */
        $image = $data['image'];
        foreach (['uid', 'fileUid', 'size'] as $key) {
            $this->assertArrayHasKey($key, $image);
            $this->assertIsInt($image[$key]);
        }

        return $image;
    }

    /**
     * The `field` of every entry of an error envelope.
     *
     * @return list<string|null>
     */
    private function errorFields(ResponseInterface $response): array
    {
        $body = $this->jsonBody($response);
        $this->assertArrayHasKey('errors', $body);
        $this->assertIsArray($body['errors']);
        $this->assertNotSame([], $body['errors'], 'A rejected upload names at least one message.');

        $fields = [];
        foreach ($body['errors'] as $error) {
            $this->assertIsArray($error);
            $this->assertArrayHasKey('field', $error);
            $fields[] = $error['field'];
        }

        return $fields;
    }

    /**
     * The live `sys_file_reference` uids of the image relation of one profile.
     *
     * @return list<int>
     */
    private function referenceUidsOfProfile(int $profileUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::FILE_REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid')
            ->from(self::FILE_REFERENCE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::PROFILE_TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('image')),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($profileUid)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)$row['uid'], $rows);
    }

    /**
     * The rows of one table carrying one uid — `[]` for a row that is gone.
     *
     * The counterpart of `rawRow()`, which asserts that the row exists.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $table, int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
