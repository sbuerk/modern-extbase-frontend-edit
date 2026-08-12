<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Controller;

use Psr\Http\Message\ResponseInterface;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\AddressDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\EmailDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Mapper\ProfileDataMapper;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence\ProfilePersistenceService;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence\WorkspaceGuard;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\AddressEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\EmailEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use SBUERK\ModernExtbaseFrontendEdit\Http\JsonEnvelope;
use SBUERK\ModernExtbaseFrontendEdit\Http\ProfileDocumentFactory;
use SBUERK\ModernExtbaseFrontendEdit\Security\ProfileOwnershipResolverInterface;
use SBUERK\ModernExtbaseFrontendEdit\Validation\AddressRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\DtoValidator;
use SBUERK\ModernExtbaseFrontendEdit\Validation\EmailRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Exception\UnknownPropertyException;
use SBUERK\ModernExtbaseFrontendEdit\Validation\ProfileRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\RuleSetInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Error\Result;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\View\JsonView;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

/**
 * The write side of the feature: the JSON endpoints of the edit plugin.
 *
 * The transport is a dedicated page `typeNum` rendering one `EXTBASEPLUGIN`
 * with `config.disableAllHeaderCode = 1` — not eID, which returns before
 * authentication and before the request token middleware, and not a PSR-15
 * middleware, which has no `frontend.typoscript` request attribute and
 * therefore dies in `FrontendConfigurationManager::getTypoScriptSetup()` on the
 * first repository call. The reasoning is written out in
 * `docs/frontend-edit/ajax-transport.md`.
 *
 * ## The payload is read by hand, and nothing in Extbase guards it
 *
 * `ServerRequestFactory` fills the parsed body from `$_POST` only
 * (`cms-core/Classes/Http/ServerRequestFactory.php:99-104`), so a request with
 * `Content-Type: application/json` has a `null` parsed body and Extbase sees no
 * arguments at all. Every action here therefore declares no argument, reads the
 * raw body and decodes it itself.
 *
 * **Say this out loud in review:** Extbase's property mapper and its validator
 * machinery never see this payload. `__trustedProperties` does not exist for a
 * JSON request, so the HMAC signed allow-list it normally builds does not exist
 * either. The defences are the ones written here and in the DTO layer, and
 * nowhere else.
 *
 * ## The four rules this class exists to enforce
 *
 * 1. **The record is resolved from the session.** `findByUid()` does not appear
 *    in this file. {@see resolveOwnedProfile()} asks the ownership resolver for
 *    the set the *session* owns, and a client uid only ever filters that set.
 *    Children are addressed through owner constrained finders that carry the
 *    resolved parent uid as a query constraint, so a foreign child uid matches
 *    nothing.
 * 2. **A request token is verified on every write**, in its three states —
 *    {@see assertRequestToken()}.
 * 3. **`404` is uniform.** "Not yours" and "does not exist" produce the same
 *    status and the same body, from {@see notFound()}. A `403` on a lookup
 *    would be a positive existence oracle.
 * 4. **A write is refused while a workspace is active.** Extbase persistence is
 *    workspace blind — plain `INSERT`/`UPDATE` against the live row — so a
 *    write from a draft workspace silently modifies published content.
 *
 * ## Why the actions return JSON without a Fluid view
 *
 * `$defaultViewObjectName` is `JsonView::class`, which makes
 * `ActionController::resolveView()` return a plain `JsonView` instead of asking
 * the view factory for a Fluid view (`ActionController.php:516-521` on v14.3,
 * `:508-517` on v13.4, deprecation free on both). The view is never rendered —
 * the actions hand an encoded string to `jsonResponse()` — but resolving one is
 * not optional in `processRequest()`, and a Fluid view would make
 * `renderAssetsForRequest()` look for `Templates/ProfileAjax/<Action>.html`,
 * which does not and should not exist. With a `JsonView` that method returns at
 * its first line, because the view is not a `FluidViewAdapter`.
 *
 * The class is `final` but not `readonly`, because `ActionController` is not.
 */
final class ProfileAjaxController extends ActionController
{
    /**
     * The scope of the request token these endpoints accept.
     *
     * It is `public` because the *issuing* side needs the same string: the edit
     * plugin creates the token while it renders the editable markup, and a
     * scope that drifts apart from this one rejects every write. Both sides
     * name this constant rather than a literal.
     *
     * The value is the one `docs/frontend-edit/ajax-transport.md` writes into
     * its `RequestToken::create()` example. It is an opaque identifier — the
     * scope is not a permission and grants nothing, it only keeps a token
     * issued for something else from being replayed here.
     */
    public const REQUEST_TOKEN_SCOPE = 'modern_extbase_frontend_edit/record-save';

    /**
     * The `child` discriminator of the payload.
     *
     * A closed set, checked in {@see childType()}. It selects a DTO, a rule
     * set, a mapper and a repository, and a name outside the set is a malformed
     * request rather than a missing record — the client either knows the API or
     * it does not, and answering `404` there would be an odd way to say
     * "unknown parameter".
     */
    private const CHILD_ADDRESS = 'address';
    private const CHILD_EMAIL = 'email';

    /**
     * See the class docblock: this keeps `resolveView()` away from Fluid.
     */
    protected ?string $defaultViewObjectName = JsonView::class;

    public function __construct(
        private readonly Context $context,
        private readonly JsonEnvelope $jsonEnvelope,
        private readonly ProfileDocumentFactory $profileDocumentFactory,
        private readonly DtoValidator $dtoValidator,
        private readonly ProfileDataMapper $profileDataMapper,
        private readonly AddressDataMapper $addressDataMapper,
        private readonly EmailDataMapper $emailDataMapper,
        private readonly ProfileOwnershipResolverInterface $profileOwnershipResolver,
        private readonly AddressEditRepository $addressEditRepository,
        private readonly EmailEditRepository $emailEditRepository,
        private readonly ProfilePersistenceService $profilePersistenceService,
        private readonly WorkspaceGuard $workspaceGuard,
    ) {}

    /**
     * Disables the frontend cache for every endpoint response, successful or
     * not.
     *
     * This runs before the action and before `resolveView()`
     * (`ActionController::processRequest()`, v14 `:369` and `:377`), so it also
     * covers the callers that are about to be rejected. It changes no state, as
     * required for anything happening in an `initialize*()` method.
     *
     * **What this call does and does not do.** The page cache entry is already
     * prevented by `config.no_cache = 1` on the endpoint page type, and it has
     * to be: every action here is registered non-cacheable, so the plugin is
     * converted to `USER_INT` *before* the action runs
     * (`cms-extbase/Classes/Core/Bootstrap.php:144-151`) and the action body
     * therefore executes in the non-cached pass — which
     * `RequestHandler::handleRequest()` runs at `:234-238`, **after** it has
     * written the page cache at `:174-226`. A cache instruction set from here
     * is too late for that write.
     *
     * It is not too late for the client cache headers:
     * `getClientCacheHeaders()` is called at `:244` and reads
     * `isCachingAllowed()` (`RequestHandler.php:1218`), so this call is what
     * guarantees `Cache-Control: private, no-store` on an endpoint response
     * regardless of the TypoScript a site ships.
     */
    protected function initializeAction(): void
    {
        $cacheInstruction = $this->request->getAttribute('frontend.cache.instruction');
        if ($cacheInstruction instanceof CacheInstruction) {
            $cacheInstruction->disableCache(
                'EXT:modern_extbase_frontend_edit: profile editing endpoint, response is per frontend user'
            );
        }
    }

    /**
     * Returns the profile of the calling session, with all of its children.
     *
     * The payload is optional and may carry a `uid`, which **filters** the
     * owned set — it never seeds a lookup. Without it the caller gets the owned
     * profile with the lowest uid; this extension stores one profile per
     * frontend user, but the resolver interface allows several, so the choice
     * is made deterministic rather than left to the query order.
     *
     * No request token is required here, and no login check is made. A read
     * changes nothing, and refusing an anonymous caller with `403` would make
     * this endpoint answer differently for "not logged in" than for "logged in,
     * not the owner" — the enumeration oracle that
     * `docs/frontend-edit/authorization.md` requires to be closed. Both end in
     * {@see notFound()} with the identical body, because an anonymous caller
     * owns nothing.
     */
    public function readAction(): ResponseInterface
    {
        $payload = $this->beginRead();

        return $this->respondWith($this->resolveOwnedProfile($payload, false));
    }

    /**
     * Saves every writable property of one record at once.
     *
     * Without a `child` key the record is the profile itself; with one it is
     * the addressed child, which is why this is one action and not three. The
     * payload of a child save is that child's own DTO, so nothing about the
     * parent can be written through it.
     *
     * `hidden` is not part of any of those DTOs and cannot be reached from
     * here — publishing is {@see setChildVisibilityAction()}. Neither can `pid`
     * or `uid`: both are absent from every DTO, and the mappers dispatch
     * through a closed `switch` that throws for anything else.
     */
    public function saveAction(): ResponseInterface
    {
        $payload = $this->beginWrite();
        $profile = $this->resolveOwnedProfile($payload, true);
        $data = $this->requiredObject($payload, 'data');
        $childType = $this->childType($payload);

        if ($childType === null) {
            $profileData = ProfileData::fromArray($data);
            $this->assertValid($this->dtoValidator->validate(new ProfileRuleSet(), $profileData));
            $this->profileDataMapper->map($profileData, $profile);
            $this->profilePersistenceService->saveProfile($profile);

            return $this->respondWith($profile);
        }

        $childUid = $this->requiredUid($payload, 'childUid');
        if ($childType === self::CHILD_ADDRESS) {
            $address = $this->resolveAddress($profile, $childUid);
            $addressData = AddressData::fromArray($data);
            $this->assertValid($this->dtoValidator->validate(new AddressRuleSet(), $addressData));
            $this->addressDataMapper->map($addressData, $address);
            $this->profilePersistenceService->saveAddress($address);

            return $this->respondWith($profile);
        }

        $email = $this->resolveEmail($profile, $childUid);
        $emailData = EmailData::fromArray($data);
        $this->assertValid($this->dtoValidator->validate(new EmailRuleSet(), $emailData));
        $this->emailDataMapper->map($emailData, $email);
        $this->profilePersistenceService->saveEmail($email);

        return $this->respondWith($profile);
    }

    /**
     * Saves one named field of one record, for the inline editor.
     *
     * The field name is validated against the rule set before it selects
     * anything: the rule set is the single whitelist of addressable names, and
     * `DtoValidator::validateProperty()` rejects a name it does not declare
     * with an {@see UnknownPropertyException}. Only then does the name reach a
     * mapper, whose `applyProperty()` is a closed `switch` — so an unknown name
     * is refused twice, by two lists a unit test keeps in sync.
     */
    public function saveFieldAction(): ResponseInterface
    {
        $payload = $this->beginWrite();
        $profile = $this->resolveOwnedProfile($payload, true);
        $field = $this->requiredString($payload, 'field');
        $value = $this->requiredFieldValue($payload);
        $childType = $this->childType($payload);

        if ($childType === null) {
            $this->assertValid($this->validateField(new ProfileRuleSet(), $field, $value));
            $this->profileDataMapper->applyProperty($profile, $field, $value);
            $this->profilePersistenceService->saveProfile($profile);

            return $this->respondWith($profile);
        }

        $childUid = $this->requiredUid($payload, 'childUid');
        if ($childType === self::CHILD_ADDRESS) {
            $address = $this->resolveAddress($profile, $childUid);
            $this->assertValid($this->validateField(new AddressRuleSet(), $field, $value));
            $this->addressDataMapper->applyProperty($address, $field, $value);
            $this->profilePersistenceService->saveAddress($address);

            return $this->respondWith($profile);
        }

        $email = $this->resolveEmail($profile, $childUid);
        $this->assertValid($this->validateField(new EmailRuleSet(), $field, $value));
        $this->emailDataMapper->applyProperty($email, $field, $value);
        $this->profilePersistenceService->saveEmail($email);

        return $this->respondWith($profile);
    }

    /**
     * Adds one child to the profile of the calling session.
     *
     * The new record is built through the mapper's `mapCollection()` rather
     * than with `new Address()` here, and that is not a detour: that method is
     * where the extension decides a new child's `pid`, from the already
     * resolved parent record. Creating the child in this controller would
     * either duplicate that rule or leave the new row to
     * `persistence.storagePid` — which `Backend::determineStoragePageIdForNewRecord()`
     * consults only after the object's own pid anyway.
     *
     * The intended set is the owned collection plus the new child, appended
     * last, so the new record sorts at the end.
     */
    public function addChildAction(): ResponseInterface
    {
        $payload = $this->beginWrite();
        $profile = $this->resolveOwnedProfile($payload, true);
        $data = $this->requiredObject($payload, 'data');
        $childType = $this->requiredChildType($payload);

        if ($childType === self::CHILD_ADDRESS) {
            $addressData = AddressData::fromArray($data);
            $this->assertValid($this->dtoValidator->validate(new AddressRuleSet(), $addressData));
            $created = $this->profileDataMapper->mapAddresses($profile, ['new' => $addressData], [])->toArray();
            $address = array_values($created)[0] ?? null;
            if (!$address instanceof Address) {
                $this->malformed(1786495911, 'The address could not be created from the given payload.');
            }
            $owned = $this->ownedAddresses($profile);
            $this->profilePersistenceService->saveAddresses($profile, $this->storageOf([...$owned, $address]), $owned);

            return $this->respondWith($profile);
        }

        $emailData = EmailData::fromArray($data);
        $this->assertValid($this->dtoValidator->validate(new EmailRuleSet(), $emailData));
        $created = $this->profileDataMapper->mapEmails($profile, ['new' => $emailData], [])->toArray();
        $email = array_values($created)[0] ?? null;
        if (!$email instanceof Email) {
            $this->malformed(1786495912, 'The e-mail address could not be created from the given payload.');
        }
        $owned = $this->ownedEmails($profile);
        $this->profilePersistenceService->saveEmails($profile, $this->storageOf([...$owned, $email]), $owned);

        return $this->respondWith($profile);
    }

    /**
     * Removes one child of the profile of the calling session.
     *
     * The intended set is the owned collection without the addressed child, and
     * the difference between the two is what the persistence service deletes.
     * Detaching alone would leave the row behind with `profile = 0` and
     * `sorting = 0` — invisible everywhere and never cleaned up.
     */
    public function removeChildAction(): ResponseInterface
    {
        $payload = $this->beginWrite();
        $profile = $this->resolveOwnedProfile($payload, true);
        $childUid = $this->requiredUid($payload, 'childUid');
        $childType = $this->requiredChildType($payload);

        if ($childType === self::CHILD_ADDRESS) {
            // Resolved first, so that a uid which is not part of the owned
            // aggregate answers with the uniform 404 instead of silently
            // removing nothing.
            $this->resolveAddress($profile, $childUid);
            $owned = $this->ownedAddresses($profile);
            $this->profilePersistenceService->saveAddresses(
                $profile,
                $this->storageOf($this->withoutUid($owned, $childUid)),
                $owned
            );

            return $this->respondWith($profile);
        }

        $this->resolveEmail($profile, $childUid);
        $owned = $this->ownedEmails($profile);
        $this->profilePersistenceService->saveEmails(
            $profile,
            $this->storageOf($this->withoutUid($owned, $childUid)),
            $owned
        );

        return $this->respondWith($profile);
    }

    /**
     * Puts the children of one collection into the submitted order.
     *
     * `order` has to be a **permutation of the whole collection**, and that is
     * a security property rather than API pedantry: the intended set replaces
     * the collection wholesale, so a short list would drop every record it
     * omits — and the persistence service would then delete them as orphans. A
     * wrong length or a duplicate uid is refused before anything is touched,
     * and a uid that is not a member produces the same `404` as one that does
     * not exist.
     */
    public function reorderChildrenAction(): ResponseInterface
    {
        $payload = $this->beginWrite();
        $profile = $this->resolveOwnedProfile($payload, true);
        $order = $this->requiredUidList($payload, 'order');
        $childType = $this->requiredChildType($payload);

        if ($childType === self::CHILD_ADDRESS) {
            $owned = $this->ownedAddresses($profile);
            $this->profilePersistenceService->saveAddresses(
                $profile,
                $this->storageOf($this->ordered($order, $owned)),
                $owned
            );

            return $this->respondWith($profile);
        }

        $owned = $this->ownedEmails($profile);
        $this->profilePersistenceService->saveEmails(
            $profile,
            $this->storageOf($this->ordered($order, $owned)),
            $owned
        );

        return $this->respondWith($profile);
    }

    /**
     * Sets the hidden state of one child.
     *
     * `hidden` is not a property of any DTO and no mapper can write it, so this
     * action is the only path to the column — and it takes an explicit boolean
     * rather than flipping the stored value. An idempotent endpoint is what a
     * client with an optimistic UI needs; a real toggle answers differently
     * depending on a state the client may have wrong.
     */
    public function setChildVisibilityAction(): ResponseInterface
    {
        $payload = $this->beginWrite();
        $profile = $this->resolveOwnedProfile($payload, true);
        $childUid = $this->requiredUid($payload, 'childUid');
        $hidden = $this->requiredBool($payload, 'hidden');
        $childType = $this->requiredChildType($payload);

        if ($childType === self::CHILD_ADDRESS) {
            $address = $this->resolveAddress($profile, $childUid);
            $address->setHidden($hidden);
            $this->profilePersistenceService->saveAddress($address);

            return $this->respondWith($profile);
        }

        $email = $this->resolveEmail($profile, $childUid);
        $email->setHidden($hidden);
        $this->profilePersistenceService->saveEmail($email);

        return $this->respondWith($profile);
    }

    /**
     * The prelude of a reading endpoint: transport checks, then the payload.
     *
     * @return array<string, mixed>
     */
    private function beginRead(): array
    {
        $this->assertPostRequest();

        return $this->decodePayload();
    }

    /**
     * The prelude of a writing endpoint, in the order the checks have to
     * happen.
     *
     * Transport first, because a `GET` or a form encoded body is not a request
     * this endpoint can answer at all. Then the request token, then the
     * session, then the workspace — each of which refuses before a single
     * request value is looked at, so a rejected caller learns nothing about the
     * payload it sent.
     *
     * @return array<string, mixed>
     */
    private function beginWrite(): array
    {
        $this->assertPostRequest();
        $this->assertRequestToken();
        $this->assertAuthenticated();
        $this->assertLiveWorkspace();

        return $this->decodePayload();
    }

    /**
     * `POST` with a JSON body, or nothing.
     *
     * `RequestBuilder` merges body parameters for `POST` only
     * (`cms-extbase/Classes/Mvc/Web/RequestBuilder.php:91-99`) and the request
     * token middleware accepts `POST`, `PUT` and `PATCH`
     * (`RequestTokenMiddleware.php:47`); the intersection is `POST`, so the verb
     * carries no information here and every endpoint uses the same one.
     *
     * The media type check is a second, independent CSRF barrier: a cross
     * origin `<form>` can only produce `application/x-www-form-urlencoded`,
     * `multipart/form-data` or `text/plain`, so it cannot reach an endpoint
     * that insists on `application/json` without a preflight the browser
     * refuses to send. That is cheap, and it does not replace the request
     * token.
     */
    private function assertPostRequest(): void
    {
        if ($this->request->getMethod() !== 'POST') {
            $this->fail(405, 1786495901, 'This endpoint accepts POST requests only.', ['Allow' => 'POST']);
        }

        $mediaType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'))[0]));
        if ($mediaType !== 'application/json') {
            $this->fail(400, 1786495902, 'This endpoint accepts a JSON request body only.');
        }
    }

    /**
     * Verifies the received request token in all three of its states.
     *
     * `SecurityAspect::getReceivedRequestToken()` is documented on the aspect
     * itself (`cms-core/Classes/Context/SecurityAspect.php:31-35`, accessor at
     * `:75-78`) and returns three different things, which the request token
     * middleware assigns:
     *
     * - `null` — no token was received. `resolveReceivedRequestToken()` returns
     *   `null` when neither the `X-TYPO3-RequestToken` header nor the
     *   `__RequestToken` body parameter carried a value
     *   (`RequestTokenMiddleware.php:64`, `:119-121`).
     * - `false` — a token was received and could not be verified:
     *   `RequestToken::fromHashSignedJwt()` raised a `RequestTokenException`,
     *   and the middleware catches it and assigns `false`
     *   (`RequestTokenMiddleware.php:65-69`).
     * - a `RequestToken` — the JWT was signed with a nonce this browser holds.
     *   That still says nothing about *which* token it is, so the scope is
     *   compared as well. Core compares it exactly this way for the login
     *   request token (`AbstractUserAuthentication.php:466`).
     *
     * Only the third state, carrying our scope, proceeds. All three failures
     * answer with one status, one code and one message: the caller sent the
     * token, so telling the cases apart would inform nobody but a log reader.
     *
     * Transport is the header rather than the body parameter, because the body
     * parameter is read from `getParsedBody()`
     * (`RequestTokenMiddleware.php:105-111`), which is `null` for a JSON
     * request.
     *
     * The token is **proof of visit, not authorisation**: it shows that this
     * browser loaded our page recently and says nothing about who the actor is
     * or what they may edit. Authentication and the ownership rule are separate
     * and both mandatory.
     *
     * `RequestToken` and `SecurityAspect` are `@internal`
     * (`RequestToken.php:22-25`, `SecurityAspect.php:26-29`). That is a knowing
     * trade-off recorded in `docs/frontend-edit/ajax-transport.md`: a home
     * grown CSRF token that is wrong is worse than a core one that is internal,
     * and both classes are unchanged since v12.0 across every version this
     * extension supports.
     */
    private function assertRequestToken(): void
    {
        $receivedRequestToken = SecurityAspect::provideIn($this->context)->getReceivedRequestToken();

        $accepted = match (true) {
            // A token was received and verified. Whether it is *our* token is a
            // separate question, unanswered until here.
            $receivedRequestToken instanceof RequestToken => $receivedRequestToken->scope === self::REQUEST_TOKEN_SCOPE,
            // A token was received and could not be verified.
            $receivedRequestToken === false => false,
            // No token was received at all.
            default => false,
        };

        if (!$accepted) {
            $this->fail(403, 1786495903, 'Request token missing or invalid.');
        }
    }

    /**
     * A write needs a logged-in frontend user.
     *
     * A `403` here is not an enumeration oracle: it answers a question about
     * the caller, not about a record. The read endpoint deliberately does not
     * ask it.
     */
    private function assertAuthenticated(): void
    {
        if ($this->resolveFrontendUserId() === 0) {
            $this->fail(403, 1786495904, 'Authentication required.');
        }
    }

    /**
     * Refuses every write while a workspace is active.
     *
     * The condition is not restated here: {@see WorkspaceGuard} owns it, the
     * persistence service asserts it again at the boundary that performs the
     * write, and this call is what turns it into a clean response instead of an
     * exception page. One rule, two callers, no second copy that can drift.
     *
     * It is load-bearing rather than defensive politeness. The Extbase storage
     * backend issues plain `INSERT`/`UPDATE` statements against the live row
     * (`cms-extbase/Classes/Persistence/Generic/Storage/Typo3DbBackend.php:84`,
     * `:114`) — no `t3ver_wsid`, no `t3ver_oid`, no `DataHandler` — so a write
     * issued from a draft workspace modifies the **published** record while the
     * editor believes otherwise.
     *
     * `409` rather than `403`: the request is authenticated and authorised, and
     * it is the state of the caller's session that makes it unanswerable.
     */
    private function assertLiveWorkspace(): void
    {
        if (!$this->workspaceGuard->areWritesAllowed()) {
            $this->fail(409, 1786495905, 'Records cannot be edited while a workspace is active.');
        }
    }

    /**
     * Reads and decodes the request body.
     *
     * The stream is rewound when it can be, because `php://input` may already
     * have been consumed and `getContents()` would then return an empty string
     * — an empty payload rather than an error, which is the kind of failure
     * that only shows up in production.
     *
     * A body that is not a JSON object is refused. An empty body and `{}` are
     * not: the read endpoint takes no parameters at all, and both are honest
     * ways for a client to say so.
     *
     * @return array<string, mixed>
     */
    private function decodePayload(): array
    {
        $body = $this->request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = trim($body->getContents());
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail(400, 1786495906, 'The request body is not valid JSON.');
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $this->fail(400, 1786495907, 'The request body has to be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The profile of the calling session, optionally narrowed by a client uid.
     *
     * This is the only entry point into persistence for every action here, and
     * its one argument comes from the session. **A client uid filters the
     * resolved set and never seeds a query** — which is why `findByUid()` does
     * not appear in this class, and why adding a fourth child type cannot
     * introduce a new hole: it introduces no new lookup.
     *
     * A uid that is not in the owned set is answered exactly like a uid that
     * does not exist.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveOwnedProfile(array $payload, bool $uidRequired): Profile
    {
        $ownedProfiles = $this->profileOwnershipResolver->resolveOwnedProfiles($this->resolveFrontendUserId());
        $uid = $uidRequired ? $this->requiredUid($payload, 'uid') : $this->optionalUid($payload, 'uid');

        if ($uid !== null) {
            foreach ($ownedProfiles as $ownedProfile) {
                if ($ownedProfile->getUid() === $uid) {
                    return $ownedProfile;
                }
            }
            $this->notFound();
        }

        $lowest = null;
        $lowestUid = null;
        foreach ($ownedProfiles as $ownedProfile) {
            $ownedProfileUid = $ownedProfile->getUid();
            if ($ownedProfileUid === null) {
                continue;
            }
            if ($lowestUid === null || $ownedProfileUid < $lowestUid) {
                $lowest = $ownedProfile;
                $lowestUid = $ownedProfileUid;
            }
        }
        if ($lowest === null) {
            $this->notFound();
        }

        return $lowest;
    }

    /**
     * One address of the resolved profile, hidden ones included.
     *
     * The client uid is one half of the constraint and the resolved parent uid
     * is the other, so the query can only return a row of an aggregate the
     * session owns. Looking the child up by uid alone and then inspecting its
     * parent pointer would move the problem one level down instead of solving
     * it.
     */
    private function resolveAddress(Profile $profile, int $childUid): Address
    {
        $address = $this->addressEditRepository->findByUidAndProfileUidIncludingHidden(
            $childUid,
            $this->uidOf($profile)
        );
        if ($address === null) {
            $this->notFound();
        }

        return $address;
    }

    private function resolveEmail(Profile $profile, int $childUid): Email
    {
        $email = $this->emailEditRepository->findByUidAndProfileUidIncludingHidden(
            $childUid,
            $this->uidOf($profile)
        );
        if ($email === null) {
            $this->notFound();
        }

        return $email;
    }

    /**
     * The addresses of the profile, in their stored order.
     *
     * Read through the edit repository rather than off
     * `$profile->getAddresses()`: relations are reconstituted with query
     * settings built from scratch (`DataMapper::getPreparedQuery()`), so the
     * parent's collection never contains the records the owner has hidden — the
     * very records this plugin exists to show and to toggle.
     *
     * This is also the `$owned` argument of every collection write: the owner
     * constrained set the persistence service diffs the intended set against.
     *
     * @return list<Address>
     */
    private function ownedAddresses(Profile $profile): array
    {
        return array_values($this->addressEditRepository->findAllByProfileUid($this->uidOf($profile))->toArray());
    }

    /**
     * @return list<Email>
     */
    private function ownedEmails(Profile $profile): array
    {
        return array_values($this->emailEditRepository->findAllByProfileUid($this->uidOf($profile))->toArray());
    }

    /**
     * The successful response: the persisted state of the whole aggregate.
     *
     * Every endpoint answers with the same document, including the ones that
     * changed a single field. A client that patched its own state optimistically
     * gets the server's version back and cannot drift, and a client that changed
     * a child gets the resulting sorting along with it.
     *
     * The document itself is built by {@see ProfileDocumentFactory}, which the
     * edit plugin uses as well — the attribute it renders into the page and the
     * body these endpoints answer with are one shape with one producer. The
     * owner constrained collections are passed in from here, because choosing
     * them is this controller's decision and not the factory's; see the class
     * docblock there.
     */
    private function respondWith(Profile $profile): ResponseInterface
    {
        return $this->jsonResponse($this->jsonEnvelope->data(
            $this->profileDocumentFactory->create(
                $profile,
                $this->ownedAddresses($profile),
                $this->ownedEmails($profile),
            ),
        ));
    }

    /**
     * Validates one submitted field and translates the "no such field" case.
     *
     * The rule set is the whitelist, so a name it does not declare is a client
     * error rather than a server one — `400`, matching the "unknown field" row
     * of the status table.
     */
    private function validateField(RuleSetInterface $ruleSet, string $field, mixed $value): Result
    {
        try {
            return $this->dtoValidator->validateProperty($ruleSet, $field, $value);
        } catch (UnknownPropertyException) {
            $this->fail(400, 1786495908, 'The submitted field is not writable.');
        }
    }

    private function assertValid(Result $result): void
    {
        if (!$result->hasErrors()) {
            return;
        }

        // 422 rather than 400: 400 is the status of Extbase's own errorAction(),
        // and the bootstrap clears the page cache for it
        // (`cms-extbase/Classes/Core/Bootstrap.php:164-166`). A user mistyping a
        // field must not evict the page cache.
        $this->failWith(422, $this->jsonEnvelope->validationErrors($result));
    }

    /**
     * The uid of the logged-in frontend user, or `0`.
     *
     * The aspect is read per call and never cached in a property: `Context` is
     * a singleton and its `frontend.user` aspect is replaced by
     * `FrontendUserAuthenticator` and again by `PreviewSimulator`.
     * `isLoggedIn()` is asked first, because `get('id')` is `0` for an
     * anonymous visitor and `0` is a value an owner column can genuinely hold.
     */
    private function resolveFrontendUserId(): int
    {
        $userAspect = $this->context->getAspect('frontend.user');
        if (!$userAspect instanceof UserAspect || !$userAspect->isLoggedIn()) {
            return 0;
        }

        return (int)$userAspect->get('id');
    }

    /**
     * The uid of a profile that came out of the owned set, which therefore has
     * one — {@see ProfileOwnershipResolverInterface} never reports an
     * unpersisted object as owned.
     */
    private function uidOf(Profile $profile): int
    {
        $uid = $profile->getUid();
        if ($uid === null || $uid <= 0) {
            $this->notFound();
        }

        return $uid;
    }

    /**
     * The intended set, as the persistence service takes it.
     *
     * A fresh storage, never the parent's live collection —
     * `ChildCollectionSynchronizer::synchronize()` refuses that outright,
     * because it empties the collection before refilling it.
     *
     * @template T of AbstractEntity
     * @param list<T> $children
     * @return ObjectStorage<T>
     */
    private function storageOf(array $children): ObjectStorage
    {
        /** @var ObjectStorage<T> $storage */
        $storage = new ObjectStorage();
        foreach ($children as $child) {
            $storage->attach($child);
        }

        return $storage;
    }

    /**
     * The collection without the record carrying the given uid, order kept.
     *
     * @template T of AbstractEntity
     * @param list<T> $children
     * @return list<T>
     */
    private function withoutUid(array $children, int $uid): array
    {
        $remaining = [];
        foreach ($children as $child) {
            if ($child->getUid() !== $uid) {
                $remaining[] = $child;
            }
        }

        return $remaining;
    }

    /**
     * Puts a collection into the submitted order, refusing anything that is not
     * a permutation of it.
     *
     * See {@see reorderChildrenAction()} for why a partial order cannot be
     * accepted. The membership check is deliberately the second one: a wrong
     * length is a client that sent a stale collection, while an unknown uid is
     * a record question and has to answer like every other one.
     *
     * @template T of AbstractEntity
     * @param list<int> $order
     * @param list<T> $children
     * @return list<T>
     */
    private function ordered(array $order, array $children): array
    {
        $byUid = [];
        foreach ($children as $child) {
            $childUid = $child->getUid();
            if ($childUid !== null) {
                $byUid[$childUid] = $child;
            }
        }

        if (count($order) !== count($byUid) || count(array_unique($order)) !== count($order)) {
            $this->malformed(1786495923, 'The submitted order has to list every record of the collection exactly once.');
        }

        $ordered = [];
        foreach ($order as $uid) {
            if (!array_key_exists($uid, $byUid)) {
                $this->notFound();
            }
            $ordered[] = $byUid[$uid];
        }

        return $ordered;
    }

    /**
     * The `child` discriminator, or `null` when the payload addresses the
     * profile itself.
     *
     * @param array<string, mixed> $payload
     * @return self::CHILD_ADDRESS|self::CHILD_EMAIL|null
     */
    private function childType(array $payload): ?string
    {
        if (!array_key_exists('child', $payload) || $payload['child'] === null) {
            return null;
        }

        return match ($payload['child']) {
            self::CHILD_ADDRESS => self::CHILD_ADDRESS,
            self::CHILD_EMAIL => self::CHILD_EMAIL,
            default => $this->malformed(1786495909, 'The addressed child collection is unknown.'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return self::CHILD_ADDRESS|self::CHILD_EMAIL
     */
    private function requiredChildType(array $payload): string
    {
        return $this->childType($payload)
            ?? $this->malformed(1786495910, 'The addressed child collection is missing.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredUid(array $payload, string $key): int
    {
        return $this->optionalUid($payload, $key)
            ?? $this->malformed(1786495913, sprintf('The "%s" of the addressed record is missing.', $key));
    }

    /**
     * A record uid, which is a positive integer and nothing else.
     *
     * A numeric string is refused rather than cast. The value selects a record,
     * and accepting two spellings of it is how a check and a lookup end up
     * disagreeing about what was addressed.
     *
     * @param array<string, mixed> $payload
     */
    private function optionalUid(array $payload, string $key): ?int
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }
        if (!is_int($payload[$key]) || $payload[$key] <= 0) {
            $this->malformed(1786495914, sprintf('"%s" has to be a positive integer.', $key));
        }

        return $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        if (!isset($payload[$key]) || !is_string($payload[$key]) || $payload[$key] === '') {
            $this->malformed(1786495915, sprintf('"%s" has to be a non-empty string.', $key));
        }

        return $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredBool(array $payload, string $key): bool
    {
        if (!array_key_exists($key, $payload) || !is_bool($payload[$key])) {
            $this->malformed(1786495916, sprintf('"%s" has to be a boolean.', $key));
        }

        return $payload[$key];
    }

    /**
     * A nested JSON object, which is what a DTO is built from.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requiredObject(array $payload, string $key): array
    {
        if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
            $this->malformed(1786495917, sprintf('"%s" has to be a JSON object.', $key));
        }
        if ($payload[$key] !== [] && array_is_list($payload[$key])) {
            $this->malformed(1786495918, sprintf('"%s" has to be a JSON object.', $key));
        }

        /** @var array<string, mixed> $value */
        $value = $payload[$key];

        return $value;
    }

    /**
     * The raw value of a partial save.
     *
     * Every writable property of every DTO is declared `string`, so the wire
     * value is a string — or `null`, which the text properties accept as the
     * cleared state. Anything else is refused here rather than by the mapper's
     * type assertion, so that a wrong type answers `400` instead of producing
     * an exception page.
     *
     * @param array<string, mixed> $payload
     */
    private function requiredFieldValue(array $payload): ?string
    {
        if (!array_key_exists('value', $payload)) {
            $this->malformed(1786495919, 'The submitted "value" is missing.');
        }
        if ($payload['value'] !== null && !is_string($payload['value'])) {
            $this->malformed(1786495920, 'The submitted "value" has to be a string.');
        }

        return $payload['value'];
    }

    /**
     * A list of record uids, as a reordering carries.
     *
     * @param array<string, mixed> $payload
     * @return list<int>
     */
    private function requiredUidList(array $payload, string $key): array
    {
        if (!array_key_exists($key, $payload) || !is_array($payload[$key]) || !array_is_list($payload[$key])) {
            $this->malformed(1786495921, sprintf('"%s" has to be a JSON array of record uids.', $key));
        }

        $uids = [];
        foreach ($payload[$key] as $uid) {
            if (!is_int($uid) || $uid <= 0) {
                $this->malformed(1786495922, sprintf('"%s" has to contain positive integers only.', $key));
            }
            $uids[] = $uid;
        }

        return $uids;
    }

    /**
     * The one "not found" answer of this controller.
     *
     * Every failed lookup ends here, with one status, one code and one message,
     * so that "this record does not exist" and "this record is not yours" are
     * indistinguishable. That is the whole point, and it is why no action
     * builds a 404 of its own.
     */
    private function notFound(): never
    {
        $this->fail(404, 1786495924, 'The addressed record does not exist.');
    }

    /**
     * A malformed request: an unknown field, a missing key, a wrong type.
     */
    private function malformed(int $code, string $message): never
    {
        $this->fail(400, $code, $message);
    }

    /**
     * @param array<string, string> $headers
     */
    private function fail(int $statusCode, int $code, string $message, array $headers = []): never
    {
        $this->failWith($statusCode, $this->jsonEnvelope->error($code, $message), $headers);
    }

    /**
     * Answers with a status the frontend rendering chain would otherwise
     * flatten.
     *
     * Only responses `>= 300` reach `ResponseData` from an Extbase frontend
     * plugin (`cms-extbase/Classes/Core/Bootstrap.php:175-186`), and on TYPO3
     * v13 a plugin cannot set a status at all, so a returned `422` is not a
     * contract that holds on both versions. Throwing bypasses page assembly
     * entirely and is caught by the outermost `response-propagation` middleware
     * (`cms-core/Classes/Middleware/ResponsePropagation.php:31-40`). It is also
     * what keeps a rejected write from reaching `Bootstrap::resetSingletons()`,
     * which would call `persistAll()`.
     *
     * `throwStatus()` is not used, although it does the same thing: it writes a
     * plain text body (`ActionController.php:811-819`), and a client that always
     * parses JSON has to receive JSON on every path.
     *
     * The exception code below marks the throw *site*, not the error — the
     * error's own code travels in the body, where the client and a bug report
     * can see it. Core does the same in `throwStatus()`, which passes one
     * constant for every status it raises.
     *
     * @param array<string, string> $headers
     */
    private function failWith(int $statusCode, string $body, array $headers = []): never
    {
        $response = $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream($body));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        throw new PropagateResponseException($response, 1786495900);
    }
}
