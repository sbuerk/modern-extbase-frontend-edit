<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileAjaxController;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Security\Nonce;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Shared setup of the tests that call the JSON endpoints through a real
 * frontend request.
 *
 * These are the first tests that execute the write path at all. Everything the
 * endpoints claim — the transport, the request token, the ownership rule, the
 * uniform `404`, the workspace refusal, the orphan deletion and the dense
 * sorting — was reasoning until a request was fired at them, and every
 * assertion below is deliberately made against the **raw database rows** rather
 * than against the response document alone.
 *
 * ## What a request here consists of
 *
 * `POST` to the endpoint page type with a JSON body, an `X-TYPO3-RequestToken`
 * header and the nonce cookie that signs it. {@see sendAjaxRequest()} assembles
 * all of it; the four token modes are the three states of
 * `SecurityAspect::getReceivedRequestToken()` plus a fourth, a valid token
 * carrying somebody else's scope, which is the only way to reach the scope
 * comparison of `ProfileAjaxController::assertRequestToken()` from outside.
 *
 * The nonce and the token are created here exactly as the browser side would
 * receive them: `Nonce::create()` produces the secret,
 * `Nonce::toHashSignedJwt()` the cookie value the middleware reconstitutes, and
 * `RequestToken::toHashSignedJwt($nonce)` the header value. Both classes are
 * `@internal`, which is the same knowing trade-off the production code makes —
 * a hand written substitute would test the substitute.
 *
 * ## Why the fixtures add children to a second profile
 *
 * `ProfilePlugins.csv` gives every address and e-mail to the profile of the
 * owner, so an IDOR test written against it can only address a uid that does
 * not exist — which every implementation refuses, including a broken one.
 * `ProfileAjaxRecords.csv` adds children to the profile of the *other* frontend
 * user, and those uids are what the ownership tests submit.
 */
abstract class AbstractProfileAjaxTestCase extends AbstractProfilePluginTestCase
{
    /**
     * The page type of the endpoints, i.e. the default of the
     * `ajaxPageType` TypoScript constant that `ext_localconf.php` registers.
     */
    protected const AJAX_PAGE_TYPE = 1589;

    /**
     * The profile of {@see AbstractProfilePluginTestCase::OWNER_FRONTEND_USER_ID},
     * carrying four addresses and two e-mail addresses.
     */
    protected const OWNED_PROFILE_UID = 1;

    /**
     * The profile of {@see AbstractProfilePluginTestCase::OTHER_FRONTEND_USER_ID},
     * the one every ownership test tries to reach.
     */
    protected const FOREIGN_PROFILE_UID = 4;

    /**
     * A second profile of the owner, stored on
     * {@see AbstractProfilePluginTestCase::OUTSIDE_PAGE_ID} rather than on the
     * page the storage configuration names first.
     *
     * It is what makes "a new child takes the pid of its parent record"
     * falsifiable — see {@see setUpProfilePluginRendering()}.
     */
    protected const OWNED_PROFILE_ON_SECOND_PAGE_UID = 5;

    /**
     * A uid no profile carries. The counterpart of
     * {@see FOREIGN_PROFILE_UID} in every test asserting that "not yours" and
     * "does not exist" are indistinguishable.
     */
    protected const ABSENT_PROFILE_UID = 999;

    /**
     * The addresses of {@see OWNED_PROFILE_UID} in their stored order, hidden
     * ones included — which is the order the endpoints read and write.
     *
     * Uid `4` is the hidden one, and it is deliberately part of the list: it is
     * absent from `$profile->getAddresses()` and present in the owner
     * constrained finder, so a collection write that goes through the parent's
     * live relation instead would silently drop it.
     *
     * @var list<int>
     */
    protected const OWNED_ADDRESS_UIDS = [2, 3, 1, 4];

    /**
     * The e-mail addresses of {@see OWNED_PROFILE_UID} in their stored order.
     *
     * @var list<int>
     */
    protected const OWNED_EMAIL_UIDS = [2, 1];

    /**
     * An address of {@see FOREIGN_PROFILE_UID}.
     */
    protected const FOREIGN_ADDRESS_UID = 11;

    /**
     * An e-mail address of {@see FOREIGN_PROFILE_UID}.
     */
    protected const FOREIGN_EMAIL_UID = 11;

    /**
     * A uid no child record carries, in either table.
     */
    protected const ABSENT_CHILD_UID = 998;

    /**
     * A request token is sent, signed with a nonce this "browser" holds, and
     * carries the scope the endpoints accept. The only mode that may proceed.
     */
    protected const TOKEN_VALID = 'valid';

    /**
     * No `X-TYPO3-RequestToken` header at all —
     * `SecurityAspect::getReceivedRequestToken()` answers `null`.
     */
    protected const TOKEN_ABSENT = 'absent';

    /**
     * A header value that is not a verifiable JWT —
     * `SecurityAspect::getReceivedRequestToken()` answers `false`.
     */
    protected const TOKEN_INVALID = 'invalid';

    /**
     * A properly signed token carrying a different scope — the aspect answers
     * with a `RequestToken`, and only the scope comparison refuses it.
     */
    protected const TOKEN_FOREIGN_SCOPE = 'foreignScope';

    /**
     * The scope of {@see TOKEN_FOREIGN_SCOPE}: a token issued for some other
     * feature of the same installation, which is what the scope comparison
     * exists to keep out.
     */
    protected const FOREIGN_TOKEN_SCOPE = 'some_other_extension/record-save';

    /**
     * The tables a request must not change when it is refused, and the tables
     * every successful write is read back from.
     *
     * @var list<string>
     */
    protected const RECORD_TABLES = [
        self::PROFILE_TABLE,
        self::ADDRESS_TABLE,
        self::EMAIL_TABLE,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/ProfileAjaxRecords.csv');
    }

    /**
     * The classic TypoScript flavour of the parent class, with **two** storage
     * pages instead of one.
     *
     * That difference is the only one, and it exists so that one rule can be
     * falsified at all: a new child record takes its `pid` from the parent
     * record, never from `persistence.storagePid`. With a single storage page
     * the two values are the same number, and a test asserting the child's pid
     * passes just as happily when the parent record is not consulted.
     *
     * `Backend::determineStoragePageIdForNewRecord()` casts the configured list
     * to `int`, so the fallback here is page `1` while the second owned profile
     * of the fixture lives on page `5`.
     */
    protected function setUpProfilePluginRendering(): void
    {
        $this->setUpFrontendRootPage(
            self::STORAGE_PAGE_ID,
            [],
            [
                'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/',
                'constants' => implode(LF, [
                    'plugin.tx_modernextbasefrontendedit.persistence.storagePid = '
                        . self::STORAGE_PAGE_ID . ',' . self::OUTSIDE_PAGE_ID,
                    'plugin.tx_modernextbasefrontendedit.settings.showPageUid = ' . self::SHOW_PAGE_ID,
                    'plugin.tx_modernextbasefrontendedit.settings.editPageUid = ' . self::EDIT_PAGE_ID,
                ]) . LF,
                'config' => self::PAGE_TYPOSCRIPT . LF,
            ],
        );
    }

    /**
     * Fires one request at an endpoint.
     *
     * Everything is a named argument, because a test that sends a `GET`, a test
     * that sends a form encoded body and a test that sends no token differ from
     * the happy path in exactly one of them — and stating that one is what makes
     * the test readable.
     *
     * `$rawBody` takes precedence over `$payload` and exists for the malformed
     * request tests, which have to send a body that `json_encode()` cannot
     * produce.
     *
     * @param array<string, mixed>|null $payload
     */
    protected function sendAjaxRequest(
        string $action,
        ?array $payload = null,
        ?int $frontendUserId = null,
        string $requestToken = self::TOKEN_VALID,
        string $method = 'POST',
        ?string $contentType = 'application/json',
        ?string $rawBody = null,
        ?int $workspaceId = null,
    ): ResponseInterface {
        $request = (new InternalRequest($this->ajaxUri($action)))->withMethod($method);

        if ($contentType !== null) {
            $request = $this->asInternalRequest($request->withHeader('Content-Type', $contentType));
        }

        if ($requestToken !== self::TOKEN_ABSENT) {
            $token = $this->requestTokenParts($requestToken);
            $request = $this->asInternalRequest($request->withHeader(RequestToken::HEADER_NAME, $token['headerValue']));
            $request = $request->withCookieParams(
                array_replace($request->getCookieParams(), [$token['cookieName'] => $token['cookieValue']]),
            );
        }

        $body = $rawBody ?? ($payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        if ($body !== null) {
            $request->getBody()->write($body);
        }

        $context = new InternalRequestContext();
        if ($frontendUserId !== null) {
            $context = $context->withFrontendUserId($frontendUserId);
        }
        if ($workspaceId !== null) {
            $context = $context->withWorkspaceId($workspaceId);
        }

        return $this->executeFrontendSubRequest($request, $context);
    }

    /**
     * The endpoint URL, with a valid cHash.
     *
     * Without one `PageArgumentValidator` answers `404` before Extbase is
     * reached, and every test below would assert a status the endpoints never
     * produced. The calculation mirrors the middleware's own — the dynamic
     * arguments plus the page id, with `type` filtered out by the calculator as
     * a core parameter.
     */
    protected function ajaxUri(string $action): string
    {
        $pluginArguments = [
            'tx_modernextbasefrontendedit_ajax' => [
                'controller' => 'ProfileAjax',
                'action' => $action,
            ],
        ];

        $cacheHashCalculator = $this->get(CacheHashCalculator::class);
        $cacheHash = $cacheHashCalculator->calculateCacheHash(
            $cacheHashCalculator->getRelevantParameters(
                HttpUtility::buildQueryString($pluginArguments + ['id' => self::STORAGE_PAGE_ID]),
            ),
        );

        return 'https://acme.com/' . HttpUtility::buildQueryString(
            $pluginArguments + ['type' => self::AJAX_PAGE_TYPE, 'cHash' => $cacheHash],
            '?',
        );
    }

    /**
     * The decoded response document, asserting on the way that it is one.
     *
     * The media type is asserted here rather than in a test of its own, so that
     * every single assertion made about a body has already established that the
     * body arrived as `application/json`.
     *
     * @return array<string, mixed>
     */
    protected function jsonBody(ResponseInterface $response): array
    {
        $this->assertStringStartsWith(
            'application/json',
            $response->getHeaderLine('Content-Type'),
            'The endpoint answers with a JSON media type.',
        );

        $decoded = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The `code` of every entry of an error envelope.
     *
     * @return list<int>
     */
    protected function errorCodes(ResponseInterface $response): array
    {
        $body = $this->jsonBody($response);
        $this->assertArrayHasKey('errors', $body);
        $this->assertIsArray($body['errors']);

        $codes = [];
        foreach ($body['errors'] as $error) {
            $this->assertIsArray($error);
            $this->assertArrayHasKey('code', $error);
            $this->assertIsInt($error['code']);
            $codes[] = $error['code'];
        }

        return $codes;
    }

    /**
     * The `data` object of a success envelope.
     *
     * @return array<string, mixed>
     */
    protected function successData(ResponseInterface $response): array
    {
        $body = $this->jsonBody($response);
        $this->assertArrayHasKey('data', $body, 'A successful response carries a data envelope.');
        $this->assertIsArray($body['data']);

        /** @var array<string, mixed> $data */
        $data = $body['data'];

        return $data;
    }

    /**
     * The uids of the `addresses` or `emails` list of a success envelope, in
     * the order the server returned them.
     *
     * @param array<string, mixed> $data
     * @return list<int>
     */
    protected function childUidsOf(array $data, string $collection): array
    {
        $this->assertArrayHasKey($collection, $data);
        $this->assertIsArray($data[$collection]);

        $uids = [];
        foreach ($data[$collection] as $child) {
            $this->assertIsArray($child);
            $this->assertArrayHasKey('uid', $child);
            $this->assertIsInt($child['uid']);
            $uids[] = $child['uid'];
        }

        return $uids;
    }

    /**
     * Every row of the three record tables, straight from the database.
     *
     * This is the "and nothing was written" assertion of the security tests,
     * and it is deliberately the whole row of every record rather than the one
     * column a test happens to think about: a guard that fails open rarely
     * writes only what the test expected it to.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function recordSnapshot(): array
    {
        $snapshot = [];
        foreach (self::RECORD_TABLES as $table) {
            $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $snapshot[$table] = $queryBuilder
                ->select('*')
                ->from($table)
                ->orderBy('uid')
                ->executeQuery()
                ->fetchAllAssociative();
        }

        return $snapshot;
    }

    /**
     * One raw row by uid, with every restriction removed.
     *
     * @return array<string, mixed>
     */
    protected function rawRow(string $table, int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($row, sprintf('Row %d of "%s" exists.', $uid, $table));

        return $row;
    }

    /**
     * The `sorting` column of the given child records, keyed by uid.
     *
     * Read raw, because the point of the reordering tests is what was written
     * into the column — reading the order back through the same finder the
     * write used would pass for a collection that was never persisted.
     *
     * @param list<int> $uids
     * @return array<int, int>
     */
    protected function sortingOf(string $table, array $uids): array
    {
        $sorting = $this->readIntColumnByUid($table, 'sorting');

        $selected = [];
        foreach ($uids as $uid) {
            $this->assertArrayHasKey($uid, $sorting);
            $selected[$uid] = $sorting[$uid];
        }

        return $selected;
    }

    /**
     * The nonce cookie and the header value of one token mode.
     *
     * @return array{cookieName: string, cookieValue: string, headerValue: string}
     */
    private function requestTokenParts(string $mode): array
    {
        $nonce = Nonce::create();
        $headerValue = match ($mode) {
            self::TOKEN_VALID => RequestToken::create(ProfileAjaxController::REQUEST_TOKEN_SCOPE)->toHashSignedJwt($nonce),
            self::TOKEN_FOREIGN_SCOPE => RequestToken::create(self::FOREIGN_TOKEN_SCOPE)->toHashSignedJwt($nonce),
            // Three segments, so this fails in the signature verification of
            // `RequestToken::fromHashSignedJwt()` rather than in a string split.
            self::TOKEN_INVALID => 'eyJhbGciOiJIUzI1NiJ9.eyJzY29wZSI6ImZvcmdlZCJ9.notavalidsignature',
            default => throw new \LogicException(sprintf('Unknown request token mode "%s".', $mode), 1786500001),
        };

        // The site of these tests is served over TLS, so the middleware reads
        // the `__Secure-` prefixed cookie name and nothing else
        // (`RequestTokenMiddleware::resolveNoncePool()`).
        return [
            'cookieName' => '__Secure-typo3nonce_' . $nonce->getSigningIdentifier()->name,
            'cookieValue' => $nonce->toHashSignedJwt(),
            'headerValue' => $headerValue,
        ];
    }

    /**
     * Narrows the return type of the PSR-7 `with*()` methods that are declared
     * on `MessageInterface` and therefore lose the concrete class.
     */
    private function asInternalRequest(MessageInterface $request): InternalRequest
    {
        $this->assertInstanceOf(InternalRequest::class, $request);

        return $request;
    }
}
