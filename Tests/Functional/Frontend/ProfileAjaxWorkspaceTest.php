<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The workspace guard, exercised by request.
 *
 * Extbase persistence is workspace blind: `Typo3DbBackend` issues plain
 * `INSERT`/`UPDATE` statements against the live row — no `t3ver_wsid`, no
 * `t3ver_oid`, no `DataHandler`. A write issued while a workspace is active
 * therefore does not produce a draft, it modifies the **published** record
 * while the editor believes the opposite. The guard is what makes the feature
 * refuse rather than corrupt, and this is the test that proves the refusal
 * reaches the wire as a clean `409`.
 *
 * ## Why a fixture extension activates the workspace
 *
 * `InternalRequestContext::withWorkspaceId()` is applied by the testing
 * framework through `BackendUserAuthentication::setTemporaryWorkspace()`, which
 * resolves the workspace against the `sys_workspace` schema. That table and its
 * TCA ship with EXT:workspaces, which this extension does not depend on and
 * which is **not** part of the dependency set — so the call fails silently and
 * the request would run in the live workspace. `tests/workspace-fixture`
 * therefore sets the `workspace` aspect of the shared `Context` directly, which
 * is the signal `WorkspaceGuard` reads and the same one
 * `BackendUserAuthenticator` writes in a real request. Its docblock states what
 * it does and does not simulate.
 *
 * The control case below is what keeps that honest: with the same fixture
 * extension loaded and workspace `0` requested, the identical write succeeds.
 * A middleware that broke every request would fail that test rather than pass
 * this one.
 */
final class ProfileAjaxWorkspaceTest extends AbstractProfileAjaxTestCase
{
    /**
     * The extension itself is repeated from the parent class, because
     * redeclaring the property replaces it.
     */
    protected array $testExtensionsToLoad = [
        'sbuerk/modern-extbase-frontend-edit',
        'fgtclb/environment-state-manager',
        'tests/workspace-fixture',
    ];

    /**
     * Every endpoint that writes, with a payload that succeeds in the live
     * workspace — see {@see ProfileAjaxAuthorizationTest::writingEndpoints()}
     * for why the payloads are complete and why the multipart upload is a row
     * of this provider rather than a test of its own.
     *
     * The refusal is what this provider is for, and it is asserted for all
     * eight endpoints on both core versions. Only the **control case** needs
     * the upload split off, because only there does the request reach the
     * storage — see {@see jsonWritingEndpoints()}.
     *
     * @return \Generator<string, array{action: string, payload: array<string, mixed>, multipart?: bool}>
     */
    public static function writingEndpoints(): \Generator
    {
        yield from self::jsonWritingEndpoints();
        yield 'uploadImage' => [
            'action' => 'uploadImage',
            'payload' => ['uid' => self::IMAGE_PROFILE_UID],
            'multipart' => true,
        ];
    }

    /**
     * The seven endpoints of {@see writingEndpoints()} whose transport is a
     * JSON body.
     *
     * They are the rows the control case below can assert on **both** core
     * versions: a JSON write never touches `ResourceStorage`, so the reason the
     * upload is a v14-only success — see
     * {@see AbstractProfileAjaxTestCase::UPLOAD_CANNOT_BE_SIMULATED_ON_CORE_13}
     * — does not apply to them.
     *
     * @return \Generator<string, array{action: string, payload: array<string, mixed>}>
     */
    public static function jsonWritingEndpoints(): \Generator
    {
        yield 'save' => [
            'action' => 'save',
            'payload' => [
                'uid' => self::OWNED_PROFILE_UID,
                'data' => ['shortname' => 'drafted', 'firstname' => 'Augusta', 'lastname' => 'King'],
            ],
        ];
        yield 'saveField' => [
            'action' => 'saveField',
            'payload' => ['uid' => self::OWNED_PROFILE_UID, 'field' => 'firstname', 'value' => 'Augusta'],
        ];
        yield 'addChild' => [
            'action' => 'addChild',
            'payload' => [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'email',
                'data' => ['type' => 'private', 'email' => 'drafted@example.org'],
            ],
        ];
        yield 'removeChild' => [
            'action' => 'removeChild',
            'payload' => ['uid' => self::OWNED_PROFILE_UID, 'child' => 'address', 'childUid' => 1],
        ];
        yield 'reorderChildren' => [
            'action' => 'reorderChildren',
            'payload' => ['uid' => self::OWNED_PROFILE_UID, 'child' => 'address', 'order' => [1, 4, 3, 2]],
        ];
        yield 'setChildVisibility' => [
            'action' => 'setChildVisibility',
            'payload' => ['uid' => self::OWNED_PROFILE_UID, 'child' => 'address', 'childUid' => 1, 'hidden' => true],
        ];
        yield 'removeImage' => [
            'action' => 'removeImage',
            'payload' => ['uid' => self::IMAGE_PROFILE_UID],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('writingEndpoints')]
    #[Test]
    public function aWriteIsRefusedWhileAWorkspaceIsActiveAndWritesNothing(
        string $action,
        array $payload,
        bool $multipart = false,
    ): void {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendWriteRequest(
            action: $action,
            payload: $payload,
            multipart: $multipart,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            workspaceId: 1,
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([1786495905], $this->errorCodes($response));
        $this->assertSame($snapshot, $this->recordSnapshot(), 'The live records are byte identical afterwards.');
        $this->assertSame([], $this->storedUploadFileNames(), 'No file reached the storage.');
    }

    /**
     * The control case: the same request, the same fixture extension, workspace
     * `0`.
     *
     * Without this the test above would also pass for a fixture middleware that
     * broke the endpoint outright, and for a guard that refuses every write.
     *
     * The upload is the one endpoint this cannot cover on both core versions,
     * and it is a test of its own below rather than a row here — the refusal
     * above keeps covering it everywhere, which is the half that matters for a
     * guard.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('jsonWritingEndpoints')]
    #[Test]
    public function theSameWriteSucceedsInTheLiveWorkspace(string $action, array $payload): void
    {
        $response = $this->sendWriteRequest(
            action: $action,
            payload: $payload,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            workspaceId: 0,
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The same control case for the upload, which only TYPO3 v14 can answer
     * from a test.
     *
     * It is the `uploadImage` row of {@see writingEndpoints()}, sent with
     * workspace `0`, and it exists as a test of its own because a PHPUnit group
     * excludes a **method**, not a data set: keeping it in the provider above
     * would have taken the seven JSON control cases off the v13 run with it.
     *
     * {@see AbstractProfileAjaxTestCase::UPLOAD_CANNOT_BE_SIMULATED_ON_CORE_13}
     * states what v13 refuses, why production is unaffected, and where the same
     * path is covered on v13 instead.
     */
    #[Group(self::UPLOAD_CANNOT_BE_SIMULATED_ON_CORE_13)]
    #[Test]
    public function theSameUploadSucceedsInTheLiveWorkspace(): void
    {
        $response = $this->sendWriteRequest(
            action: 'uploadImage',
            payload: ['uid' => self::IMAGE_PROFILE_UID],
            multipart: true,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            workspaceId: 0,
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A read is not refused. It changes nothing, and the guard is about writes.
     */
    #[Test]
    public function aReadIsAnsweredWhileAWorkspaceIsActive(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'read',
            payload: ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            requestToken: self::TOKEN_ABSENT,
            workspaceId: 1,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::OWNED_PROFILE_UID, $this->successData($response)['uid']);
    }
}
