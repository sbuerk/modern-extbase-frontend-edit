<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The transport of the JSON endpoints: that the chain produces JSON at all, and
 * what it does with a request it cannot answer.
 *
 * The chain is `PAGE` → `EXTBASEPLUGIN` → `USER_INT` → `jsonResponse()`, and
 * every link of it is a place the response can turn into HTML without anything
 * looking broken. The two assertions that matter are therefore made against the
 * raw body: it parses as JSON, and it carries none of the Fluid Styled Content
 * wrapper that `lib.contentElement` would have put around it — which is exactly
 * why the page type calls `EXTBASEPLUGIN` instead of
 * `tt_content.modernextbasefrontendedit_ajax`.
 */
final class ProfileAjaxTransportTest extends AbstractProfileAjaxTestCase
{
    /**
     * The happy path of the smallest write, end to end.
     *
     * It proves the whole chain in one request: the page type resolves, the
     * plugin is registered, the action is reached, the token is accepted, the
     * profile is resolved from the session, the value is written and the
     * response is the persisted aggregate.
     */
    #[Test]
    public function saveWritesThePayloadAndAnswersWithThePersistedAggregate(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'save',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'data' => [
                    'shortname' => 'ada-l',
                    'firstname' => 'Augusta Ada',
                    'lastname' => 'King',
                    'birthday' => '1815-12-10',
                    'bio' => 'Notes on the Analytical Engine.',
                ],
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());

        $data = $this->successData($response);
        $this->assertSame(self::OWNED_PROFILE_UID, $data['uid']);
        $this->assertSame('Augusta Ada', $data['firstname']);
        $this->assertSame('1815-12-10', $data['birthday']);

        $row = $this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID);
        $this->assertSame('ada-l', $row['shortname']);
        $this->assertSame('Augusta Ada', $row['firstname']);
        $this->assertSame('King', $row['lastname']);
        $this->assertSame('Notes on the Analytical Engine.', $row['bio']);
    }

    /**
     * The partial save, which is the one the inline editor uses.
     *
     * Asserted separately from the full save because it takes an entirely
     * different route through the controller — the field name is validated
     * against the rule set and then dispatched through the mapper's closed
     * `switch`, and neither is touched by {@see saveWritesThePayloadAndAnswersWithThePersistedAggregate()}.
     */
    #[Test]
    public function saveFieldWritesTheOneSubmittedField(): void
    {
        $before = $this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID);

        $response = $this->sendAjaxRequest(
            action: 'saveField',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'field' => 'firstname',
                'value' => 'Augusta',
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Augusta', $this->successData($response)['firstname']);

        $after = $this->rawRow(self::PROFILE_TABLE, self::OWNED_PROFILE_UID);
        $this->assertSame('Augusta', $after['firstname']);
        // Everything else of the record is untouched by a partial save.
        $this->assertSame($before['lastname'], $after['lastname']);
        $this->assertSame($before['shortname'], $after['shortname']);
        $this->assertSame($before['bio'], $after['bio']);
    }

    /**
     * The response body is a JSON document and nothing else.
     *
     * `lib.contentElement` renders a plugin through the Fluid Styled Content
     * "Generic" template, whose "Default" layout wraps the output in
     * `<div id="c…" class="frame …">`. A single `=<` in the TypoScript of the
     * endpoint page type brings that back, and the response would still be a
     * `200` carrying the JSON — inside markup no client can parse.
     */
    #[Test]
    public function theResponseIsBareJsonWithoutTheContentElementWrapper(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'read',
            payload: ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            requestToken: self::TOKEN_ABSENT,
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $this->assertStringNotContainsString('<div class="frame', $body);
        $this->assertStringNotContainsString('<div id="c', $body);
        $this->assertStringNotContainsString('<!DOCTYPE', $body);
        $this->assertStringNotContainsString('<html', $body);
        $this->assertSame('{', substr($body, 0, 1), 'The body starts with the JSON document.');
        $this->assertSame('}', substr($body, -1), 'The body ends with the JSON document.');

        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    /**
     * Every endpoint of the controller, so that a newly added action cannot
     * quietly answer a `GET`.
     *
     * @return \Generator<string, array{action: string}>
     */
    public static function endpointActions(): \Generator
    {
        yield 'read' => ['action' => 'read'];
        yield 'save' => ['action' => 'save'];
        yield 'saveField' => ['action' => 'saveField'];
        yield 'addChild' => ['action' => 'addChild'];
        yield 'removeChild' => ['action' => 'removeChild'];
        yield 'reorderChildren' => ['action' => 'reorderChildren'];
        yield 'setChildVisibility' => ['action' => 'setChildVisibility'];
    }

    #[DataProvider('endpointActions')]
    #[Test]
    public function aNonPostRequestIsRefusedWithAllowPost(string $action): void
    {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendAjaxRequest(
            action: $action,
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            method: 'GET',
        );

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
        $this->assertSame([1786495901], $this->errorCodes($response));
        $this->assertSame($snapshot, $this->recordSnapshot());
    }

    /**
     * A body that is not `application/json` is refused before anything else is
     * read.
     *
     * This is the second, independent CSRF barrier: a cross origin `<form>` can
     * only produce these three media types, and none of them gets past here.
     *
     * @return \Generator<string, array{contentType: string|null}>
     */
    public static function mediaTypesAFormCanProduce(): \Generator
    {
        yield 'application/x-www-form-urlencoded' => ['contentType' => 'application/x-www-form-urlencoded'];
        yield 'multipart/form-data' => ['contentType' => 'multipart/form-data; boundary=x'];
        yield 'text/plain' => ['contentType' => 'text/plain'];
        yield 'no Content-Type at all' => ['contentType' => null];
    }

    #[DataProvider('mediaTypesAFormCanProduce')]
    #[Test]
    public function aRequestThatIsNotJsonIsRefused(?string $contentType): void
    {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendAjaxRequest(
            action: 'saveField',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'field' => 'firstname',
                'value' => 'Augusta',
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            contentType: $contentType,
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([1786495902], $this->errorCodes($response));
        $this->assertSame($snapshot, $this->recordSnapshot());
    }

    /**
     * The malformed request shapes, all of which have to answer `400` and none
     * of which may reach persistence.
     *
     * `1786495906` is "not valid JSON", `1786495907` is "not a JSON object".
     *
     * @return \Generator<string, array{rawBody: string, code: int}>
     */
    public static function malformedRequestBodies(): \Generator
    {
        yield 'truncated object' => ['rawBody' => '{"uid": 1', 'code' => 1786495906];
        yield 'not JSON at all' => ['rawBody' => 'uid=1&field=firstname', 'code' => 1786495906];
        yield 'a JSON array' => ['rawBody' => '[1, 2, 3]', 'code' => 1786495907];
        yield 'a JSON scalar' => ['rawBody' => '"firstname"', 'code' => 1786495907];
    }

    #[DataProvider('malformedRequestBodies')]
    #[Test]
    public function aMalformedBodyIsRefused(string $rawBody, int $code): void
    {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendAjaxRequest(
            action: 'saveField',
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            rawBody: $rawBody,
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([$code], $this->errorCodes($response));
        $this->assertSame($snapshot, $this->recordSnapshot());
    }

    /**
     * A value the rule set does not declare is refused, and it is refused as a
     * client error rather than as a validation failure.
     */
    #[Test]
    public function anUnknownFieldNameIsRefused(): void
    {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendAjaxRequest(
            action: 'saveField',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'field' => 'feUser',
                'value' => '2',
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([1786495908], $this->errorCodes($response));
        $this->assertSame($snapshot, $this->recordSnapshot());
    }

    /**
     * A value the rule set rejects answers `422`, with the property path and
     * the validator's own sentence — and nothing of the submitted value.
     */
    #[Test]
    public function aValueTheRuleSetRejectsAnswersUnprocessableContent(): void
    {
        $snapshot = $this->recordSnapshot();

        $response = $this->sendAjaxRequest(
            action: 'saveField',
            payload: [
                'uid' => self::OWNED_PROFILE_UID,
                'field' => 'shortname',
                'value' => '',
            ],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
        );

        $this->assertSame(422, $response->getStatusCode());

        $body = $this->jsonBody($response);
        $this->assertIsArray($body['errors']);
        $this->assertNotSame([], $body['errors']);
        $fields = array_map(
            static fn(mixed $error): mixed => is_array($error) ? ($error['field'] ?? null) : null,
            $body['errors'],
        );
        $this->assertContains('shortname', $fields);
        $this->assertSame($snapshot, $this->recordSnapshot());
    }

    /**
     * The endpoint response must never be cached, and it must never be reused
     * for another visitor.
     *
     * `Cache-Control: private, no-store` is what
     * `RequestHandler::getClientCacheHeaders()` writes for a request whose cache
     * instruction forbids caching, and it is the only part of the caching rule
     * that is visible from outside the application.
     */
    #[Test]
    public function theResponseIsMarkedPrivateAndNotStorable(): void
    {
        $response = $this->sendAjaxRequest(
            action: 'read',
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            requestToken: self::TOKEN_ABSENT,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }
}
