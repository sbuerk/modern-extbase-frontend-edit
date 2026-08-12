<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The security rules of the write path, exercised by request.
 *
 * This is the class the change exists for. Every row of the checklist in
 * `docs/frontend-edit/authorization.md` that belongs to the endpoints is
 * discharged here, and each of them asserts two things rather than one: the
 * status the caller sees, **and** that the database is byte for byte what it
 * was before the request. A guard that answers `403` and writes anyway is a
 * guard that passes a status-only test.
 *
 * ## The two payload shapes the ownership tests use
 *
 * A profile uid that the session does not own, and a child uid that belongs to
 * another session's profile. The second is the interesting one: it is the
 * insecure direct object reference the design closes by never looking a child
 * up by uid alone, and it is submitted to **every** endpoint that takes a
 * `childUid` or an order list — `save`, `saveField`, `removeChild`,
 * `reorderChildren` and `setChildVisibility`. One of them forgetting the owner
 * constrained finder is a hole in the whole feature, so no endpoint is left to
 * the argument that the others cover it.
 */
final class ProfileAjaxAuthorizationTest extends AbstractProfileAjaxTestCase
{
    /**
     * Every endpoint that writes, with a payload that would succeed for the
     * owner.
     *
     * The payloads are complete on purpose: a request that is refused before it
     * is parsed must be refused for a *valid* request just as much as for an
     * incomplete one, and a payload missing a key would let a `400` masquerade
     * as the refusal under test.
     *
     * `uploadImage` is the one entry whose transport is `multipart/form-data`
     * rather than a JSON body, so its row says so and
     * `AbstractProfileAjaxTestCase::sendWriteRequest()` builds it accordingly.
     * It is in this provider rather than in a test of its own because the rules
     * below are not per endpoint: an endpoint that is exempt from one of them is
     * a hole in the feature, whatever it transports.
     *
     * @return \Generator<string, array{action: string, payload: array<string, mixed>, multipart?: bool}>
     */
    public static function writingEndpoints(): \Generator
    {
        yield 'save' => [
            'action' => 'save',
            'payload' => [
                'uid' => self::OWNED_PROFILE_UID,
                'data' => ['shortname' => 'intruder', 'firstname' => 'In', 'lastname' => 'Truder'],
            ],
        ];
        yield 'saveField' => [
            'action' => 'saveField',
            'payload' => ['uid' => self::OWNED_PROFILE_UID, 'field' => 'firstname', 'value' => 'Intruder'],
        ];
        yield 'addChild' => [
            'action' => 'addChild',
            'payload' => [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'email',
                'data' => ['type' => 'private', 'email' => 'intruder@example.org'],
            ],
        ];
        yield 'removeChild' => [
            'action' => 'removeChild',
            'payload' => ['uid' => self::OWNED_PROFILE_UID, 'child' => 'email', 'childUid' => 1],
        ];
        yield 'reorderChildren' => [
            'action' => 'reorderChildren',
            'payload' => ['uid' => self::OWNED_PROFILE_UID, 'child' => 'email', 'order' => [1, 2]],
        ];
        yield 'setChildVisibility' => [
            'action' => 'setChildVisibility',
            'payload' => ['uid' => self::OWNED_PROFILE_UID, 'child' => 'email', 'childUid' => 1, 'hidden' => true],
        ];
        yield 'uploadImage' => [
            'action' => 'uploadImage',
            'payload' => ['uid' => self::OWNED_PROFILE_UID],
            'multipart' => true,
        ];
        yield 'removeImage' => [
            'action' => 'removeImage',
            'payload' => ['uid' => self::OWNED_PROFILE_UID],
        ];
    }

    /**
     * Nobody writes without a session.
     *
     * The request is otherwise perfect — a valid request token, a well formed
     * payload, a profile that exists — so the only reason it can be refused is
     * the login check.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('writingEndpoints')]
    #[Test]
    public function anUnauthenticatedWriteIsRefusedAndWritesNothing(
        string $action,
        array $payload,
        bool $multipart = false,
    ): void {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendWriteRequest(action: $action, payload: $payload, multipart: $multipart);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([1786495904], $this->errorCodes($response));
        $this->assertSame($snapshot, $this->recordSnapshot(), 'No record was written.');
        $this->assertSame([], $this->storedUploadFileNames(), 'No file reached the storage.');
    }

    /**
     * A logged-in caller who does not own the profile is answered exactly like
     * a caller naming a profile that does not exist.
     *
     * "Exactly" is the assertion: same status, same body, same headers. A `403`
     * here, or a different message, or a different error code, turns the
     * endpoint into a positive existence oracle — it confirms that the record
     * exists and belongs to somebody else, which is the enumeration hole the
     * design closes.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('writingEndpoints')]
    #[Test]
    public function aForeignProfileIsIndistinguishableFromAnAbsentOne(
        string $action,
        array $payload,
        bool $multipart = false,
    ): void {
        $snapshot = $this->recordSnapshot();

        $foreign = $this->sendWriteRequest(
            action: $action,
            payload: array_replace($payload, ['uid' => self::FOREIGN_PROFILE_UID]),
            multipart: $multipart,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );
        $absent = $this->sendWriteRequest(
            action: $action,
            payload: array_replace($payload, ['uid' => self::ABSENT_PROFILE_UID]),
            multipart: $multipart,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame([1786495924], $this->errorCodes($foreign));
        $this->assertSame(
            $this->comparableResponse($absent),
            $this->comparableResponse($foreign),
            'A profile of another user answers exactly like a profile that does not exist.',
        );
        $this->assertSame($snapshot, $this->recordSnapshot(), 'No record was written.');
        $this->assertSame([], $this->storedUploadFileNames(), 'No file reached the storage.');
    }

    /**
     * The read endpoint answers the same for an anonymous caller and for a
     * logged-in one who does not own the profile.
     *
     * It deliberately requires neither a token nor a session, so that these two
     * cases *can* be identical — a `403` for the anonymous caller would say
     * "log in and you will learn whether this record exists".
     */
    #[Test]
    public function readAnswersAnonymousAndNonOwnerAlike(): void
    {
        $anonymous = $this->sendAjaxRequest(
            action: 'read',
            payload: ['uid' => self::OWNED_PROFILE_UID],
            requestToken: self::TOKEN_ABSENT,
        );
        $nonOwner = $this->sendAjaxRequest(
            action: 'read',
            payload: ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OTHER_FRONTEND_USER_ID,
            requestToken: self::TOKEN_ABSENT,
        );

        $this->assertSame(404, $anonymous->getStatusCode());
        $this->assertSame([1786495924], $this->errorCodes($anonymous));
        $this->assertSame($this->comparableResponse($anonymous), $this->comparableResponse($nonOwner));
    }

    /**
     * The read endpoint without a payload answers `404` for a caller who owns
     * nothing, rather than disclosing somebody's profile.
     *
     * The fixture holds a profile whose owner column is `0`, which is the value
     * `UserAspect::get('id')` yields for an anonymous visitor. Without the
     * "deny before compare" guard of the resolver this request would return it.
     */
    #[Test]
    public function readWithoutAPayloadAnswersNotFoundForAnAnonymousCaller(): void
    {
        $response = $this->sendAjaxRequest(action: 'read', requestToken: self::TOKEN_ABSENT);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([1786495924], $this->errorCodes($response));
        $this->assertStringNotContainsString(self::UNOWNED_PROFILE_NAME, (string)$response->getBody());
    }

    /**
     * Without a uid the read endpoint answers with the owned profile of the
     * lowest uid, deterministically.
     *
     * The owner of the fixture owns three profiles: one visible on the first
     * storage page, one they hid, and one on the second storage page. Which of
     * them a payload-less read returns is a choice the controller makes rather
     * than one the query order makes, and it is the profile with the lowest
     * uid.
     */
    #[Test]
    public function readWithoutAUidAnswersWithTheOwnedProfileOfTheLowestUid(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'read',
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            requestToken: self::TOKEN_ABSENT,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::OWNED_PROFILE_UID, $this->successData($response)['uid']);
    }

    /**
     * Every endpoint that accepts a child uid or an order list, for both child
     * types.
     *
     * The `order` payloads are a **permutation of the owner's collection** with
     * one member swapped for the foreign uid, so they pass the length and
     * uniqueness check and are refused by the membership check alone. A shorter
     * list would be refused as malformed and would prove nothing about
     * ownership.
     *
     * @return \Generator<string, array{action: string, payload: array<string, mixed>, table: string, childUid: int}>
     */
    public static function endpointsTakingAChildUid(): \Generator
    {
        yield 'save, address' => [
            'action' => 'save',
            'payload' => [
                'child' => 'address',
                'childUid' => self::FOREIGN_ADDRESS_UID,
                'data' => ['type' => 'home', 'line1' => 'Intruder Lane 1', 'line2' => ''],
            ],
            'table' => self::ADDRESS_TABLE,
            'childUid' => self::FOREIGN_ADDRESS_UID,
        ];
        yield 'save, email' => [
            'action' => 'save',
            'payload' => [
                'child' => 'email',
                'childUid' => self::FOREIGN_EMAIL_UID,
                'data' => ['type' => 'private', 'email' => 'intruder@example.org'],
            ],
            'table' => self::EMAIL_TABLE,
            'childUid' => self::FOREIGN_EMAIL_UID,
        ];
        yield 'saveField, address' => [
            'action' => 'saveField',
            'payload' => [
                'child' => 'address',
                'childUid' => self::FOREIGN_ADDRESS_UID,
                'field' => 'line1',
                'value' => 'Intruder Lane 1',
            ],
            'table' => self::ADDRESS_TABLE,
            'childUid' => self::FOREIGN_ADDRESS_UID,
        ];
        yield 'saveField, email' => [
            'action' => 'saveField',
            'payload' => [
                'child' => 'email',
                'childUid' => self::FOREIGN_EMAIL_UID,
                'field' => 'email',
                'value' => 'intruder@example.org',
            ],
            'table' => self::EMAIL_TABLE,
            'childUid' => self::FOREIGN_EMAIL_UID,
        ];
        yield 'removeChild, address' => [
            'action' => 'removeChild',
            'payload' => ['child' => 'address', 'childUid' => self::FOREIGN_ADDRESS_UID],
            'table' => self::ADDRESS_TABLE,
            'childUid' => self::FOREIGN_ADDRESS_UID,
        ];
        yield 'removeChild, email' => [
            'action' => 'removeChild',
            'payload' => ['child' => 'email', 'childUid' => self::FOREIGN_EMAIL_UID],
            'table' => self::EMAIL_TABLE,
            'childUid' => self::FOREIGN_EMAIL_UID,
        ];
        yield 'setChildVisibility, address' => [
            'action' => 'setChildVisibility',
            'payload' => ['child' => 'address', 'childUid' => self::FOREIGN_ADDRESS_UID, 'hidden' => true],
            'table' => self::ADDRESS_TABLE,
            'childUid' => self::FOREIGN_ADDRESS_UID,
        ];
        yield 'setChildVisibility, email' => [
            'action' => 'setChildVisibility',
            'payload' => ['child' => 'email', 'childUid' => self::FOREIGN_EMAIL_UID, 'hidden' => true],
            'table' => self::EMAIL_TABLE,
            'childUid' => self::FOREIGN_EMAIL_UID,
        ];
        yield 'reorderChildren, address' => [
            'action' => 'reorderChildren',
            'payload' => ['child' => 'address', 'order' => [2, 3, 1, self::FOREIGN_ADDRESS_UID]],
            'table' => self::ADDRESS_TABLE,
            'childUid' => self::FOREIGN_ADDRESS_UID,
        ];
        yield 'reorderChildren, email' => [
            'action' => 'reorderChildren',
            'payload' => ['child' => 'email', 'order' => [2, self::FOREIGN_EMAIL_UID]],
            'table' => self::EMAIL_TABLE,
            'childUid' => self::FOREIGN_EMAIL_UID,
        ];
    }

    /**
     * A child uid belonging to another user's profile is refused, and that
     * child's row is untouched.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('endpointsTakingAChildUid')]
    #[Test]
    public function aChildOfAnotherProfileIsRefusedAndUntouched(
        string $action,
        array $payload,
        string $table,
        int $childUid,
    ): void {
        $snapshot = $this->recordSnapshot();
        $childBefore = $this->rawRow($table, $childUid);

        $response = $this->sendAjaxRequest(
            action: $action,
            payload: $payload + ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([1786495924], $this->errorCodes($response));
        $this->assertSame($childBefore, $this->rawRow($table, $childUid), 'The foreign child record is untouched.');
        $this->assertSame($snapshot, $this->recordSnapshot(), 'No record was written.');
    }

    /**
     * A child uid that exists nowhere answers exactly like one that belongs to
     * somebody else.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('endpointsTakingAChildUid')]
    #[Test]
    public function aForeignChildIsIndistinguishableFromAnAbsentOne(
        string $action,
        array $payload,
        string $table,
        int $childUid,
    ): void {
        $foreign = $this->sendAjaxRequest(
            action: $action,
            payload: $payload + ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );
        $absent = $this->sendAjaxRequest(
            action: $action,
            payload: $this->replaceChildUid($payload, $childUid, self::ABSENT_CHILD_UID)
                + ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame($this->comparableResponse($absent), $this->comparableResponse($foreign));
    }

    /**
     * `pid`, `uid` and the other columns a payload must not reach change none
     * of them.
     *
     * None is a property of any DTO, so there is no mapper path to any of them,
     * and the mappers dispatch through a closed `switch` that throws for
     * anything else. The record is read back raw rather than through the mapper
     * that wrote it.
     *
     * The submitted `pid` is the page the fixture calls "Elsewhere", a real
     * page of the same site, so a successful escape would move the record
     * somewhere it is genuinely visible rather than into nowhere.
     *
     * **What this test can and cannot fail on, on TYPO3 v14.** `pid` is not a
     * TCA column, and `DataMapFactory::buildDataMap()` builds its column maps
     * from `$schema->getFields()` — the TCA fields — alone, so
     * `DataMap::isPersistableProperty('pid')` is `false` and
     * `Backend::persistObject()` never writes the column for an **existing**
     * record. A `setPid()` escape on an update is therefore closed by Extbase
     * itself here, and this assertion is a regression guard rather than a
     * falsifiable one. The escape that is real is the one for a **new** record,
     * where `Backend::determineStoragePageIdForNewRecord()` prefers the
     * object's own pid over every configuration — that is
     * {@see aCreatedChildIsStoredOnThePageOfItsParentRecord()}, and it is
     * falsifiable.
     */
    #[Test]
    public function aPayloadCarryingPidAndUidChangesNeither(): void
    {
        $before = $this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID);

        $response = $this->sendAjaxRequest(
            action: 'save',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'data' => [
                    'shortname' => 'ada',
                    'firstname' => 'Ada',
                    'lastname' => 'Lovelace',
                    'pid' => self::OUTSIDE_PAGE_ID,
                    'uid' => self::FOREIGN_PROFILE_UID,
                    'feUser' => self::OTHER_FRONTEND_USER_ID,
                    'hidden' => 1,
                    'deleted' => 1,
                ],
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode(), 'The unknown keys are ignored rather than rejected.');

        $after = $this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID);
        $this->assertSame($before['pid'], $after['pid'], 'The storage page is unchanged.');
        $this->assertSame($before['uid'], $after['uid'], 'The uid is unchanged.');
        $this->assertSame($before['fe_user'], $after['fe_user'], 'The owner is unchanged.');
        $this->assertSame($before['hidden'], $after['hidden'], 'The visibility is unchanged.');
        $this->assertSame($before['deleted'], $after['deleted'], 'The record was not deleted.');
        // The profile of the other user is not the record that was addressed,
        // and the "uid" of the data object must not have made it one.
        $this->assertSame(
            $this->rawRow(self::PROFILE_TABLE, self::FOREIGN_PROFILE_UID)['shortname'],
            'radia',
        );
    }

    /**
     * A new child is stored on the page of its parent record, not on the
     * configured storage page and not where a payload asked for.
     *
     * The parent here is the owner's **second** profile, which the fixture
     * keeps on another page than the one `persistence.storagePid` names first
     * — see `AbstractProfileAjaxTestCase::setUpProfilePluginRendering()`. On a
     * single storage page the two numbers are equal and this assertion holds
     * even when the parent record is never consulted.
     */
    #[Test]
    public function aCreatedChildIsStoredOnThePageOfItsParentRecord(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'addChild',
            payload: [
                'uid' => self::OWNED_PROFILE_ON_SECOND_PAGE_UID,
                'child' => 'email',
                'data' => ['type' => 'private', 'email' => 'anita@example.org', 'pid' => self::SHOW_PAGE_ID],
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());

        $created = $this->childUidsOf($this->successData($response), 'emails');
        $this->assertCount(1, $created);

        $row = $this->rawRow(self::EMAIL_TABLE, $created[0]);
        $this->assertSame(self::OUTSIDE_PAGE_ID, (int)$row['pid'], 'The child follows its parent record.');
        $this->assertSame(
            self::OWNED_PROFILE_ON_SECOND_PAGE_UID,
            (int)$row['profile'],
            'The child points at the parent it was created for.',
        );
    }

    /**
     * The same for a newly created child of the profile on the first storage
     * page, where the payload additionally tries to publish it.
     */
    #[Test]
    public function aCreatedChildTakesItsPidFromTheParentRecord(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'addChild',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'child' => 'email',
                'data' => [
                    'type' => 'private',
                    'email' => 'new@example.org',
                    'pid' => self::OUTSIDE_PAGE_ID,
                    'hidden' => 1,
                ],
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());

        $created = $this->childUidsOf($this->successData($response), 'emails');
        $this->assertCount(count(self::OWNED_EMAIL_UIDS) + 1, $created);

        $newUid = $created[count($created) - 1];
        $this->assertNotContains($newUid, self::OWNED_EMAIL_UIDS, 'The appended record is the new one.');

        $row = $this->rawRow(self::EMAIL_TABLE, $newUid);
        $this->assertSame(
            $this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID)['pid'],
            $row['pid'],
            'The new child was stored on the page of its parent record.',
        );
        $this->assertSame(0, (int)$row['hidden'], 'The payload cannot publish or hide a record.');
    }

    /**
     * The three states of the received request token, plus the fourth case that
     * only the scope comparison catches.
     *
     * `null` (nothing received) and `false` (received and unverifiable) are
     * different values on the aspect and the same answer on the wire — telling
     * them apart would inform nobody but a log reader, and the caller sent the
     * token either way.
     *
     * @return \Generator<string, array{mode: string, expectedStatus: int}>
     */
    public static function requestTokenModes(): \Generator
    {
        yield 'no token at all' => ['mode' => self::TOKEN_ABSENT, 'expectedStatus' => 403];
        yield 'a token that cannot be verified' => ['mode' => self::TOKEN_INVALID, 'expectedStatus' => 403];
        yield 'a valid token of another scope' => ['mode' => self::TOKEN_FOREIGN_SCOPE, 'expectedStatus' => 403];
        yield 'a valid token of this scope' => ['mode' => self::TOKEN_VALID, 'expectedStatus' => 200];
    }

    #[DataProvider('requestTokenModes')]
    #[Test]
    public function onlyAValidTokenOfThisScopeMayWrite(string $mode, int $expectedStatus): void
    {
        $before = $this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID);

        $response = $this->sendAjaxRequest(
            action: 'saveField',
            payload: ['uid' => self::OWNED_PROFILE_UID, 'field' => 'firstname', 'value' => 'Augusta'],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            requestToken: $mode,
        );

        $this->assertSame($expectedStatus, $response->getStatusCode());

        $after = $this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID);
        if ($expectedStatus === 200) {
            $this->assertSame('Augusta', $after['firstname']);

            return;
        }

        $this->assertSame([1786495903], $this->errorCodes($response));
        $this->assertSame($before, $after, 'No record was written.');
    }

    /**
     * The read endpoint takes no token, and that is a property rather than an
     * oversight: it is what allows an anonymous read and a non-owner read to be
     * indistinguishable.
     */
    #[Test]
    public function readNeedsNoRequestToken(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'read',
            payload: ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            requestToken: self::TOKEN_ABSENT,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::OWNED_PROFILE_UID, $this->successData($response)['uid']);
    }

    /**
     * Replaces the addressed child uid of a payload, whether it is named by
     * `childUid` or listed in `order`.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function replaceChildUid(array $payload, int $from, int $to): array
    {
        if (array_key_exists('childUid', $payload)) {
            return array_replace($payload, ['childUid' => $to]);
        }

        $this->assertArrayHasKey('order', $payload);
        $this->assertIsArray($payload['order']);

        return array_replace($payload, [
            'order' => array_map(
                static fn(mixed $uid): mixed => $uid === $from ? $to : $uid,
                $payload['order'],
            ),
        ]);
    }

    /**
     * Everything of a response two callers can compare.
     *
     * `Set-Cookie` carries the nonce the request token was signed with and
     * `Date` the second the request was answered; both differ between two
     * requests of the same shape and neither says anything about the record.
     * Everything else — status, every other header and the body — has to be
     * identical, or the endpoint tells the caller which of the two cases they
     * hit.
     *
     * @return array{status: int, headers: array<string, array<string>>, body: string}
     */
    private function comparableResponse(ResponseInterface $response): array
    {
        $headers = $response->getHeaders();
        unset($headers['Set-Cookie'], $headers['set-cookie'], $headers['Date'], $headers['date']);

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => (string)$response->getBody(),
        ];
    }
}
