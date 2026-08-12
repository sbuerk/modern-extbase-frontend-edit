<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Controller;

use Psr\Http\Message\ResponseInterface;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence\WorkspaceGuard;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\AddressEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\EmailEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Http\ProfileDocumentFactory;
use SBUERK\ModernExtbaseFrontendEdit\Security\ProfileOwnershipResolverInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * The server side of the edit plugin: the markup the lit component enhances.
 *
 * It renders **one** record — the profile of the calling session — as readable
 * HTML, and hands the component the four things it needs in `data-` attributes:
 * the profile document, the endpoint URLs, a request token and the label map.
 * Everything else the editing surface does happens in the browser and against
 * `Controller\ProfileAjaxController`.
 *
 * ## Why this is not an action on `ProfileController`
 *
 * `ProfileController` states in its own docblock that it reads through the
 * display repository {@see \SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository}
 * "never through the `Edit\` counterparts". This action has to do the opposite:
 * the owner's editing view must show the children the owner has **hidden**, so
 * the collections are read through the edit repositories, which is exactly what
 * `ProfileAjaxController` does for the same reason. Adding the action there
 * would have meant injecting two edit repositories into the read controller and
 * falsifying the invariant its docblock asserts.
 *
 * The class is `final` but not `readonly`, because `ActionController` is not.
 *
 * ## The record comes from the session, never from the request
 *
 * There is no `profile` argument. The action resolves the owned set through
 * {@see ProfileOwnershipResolverInterface} and takes the profile with the
 * lowest uid, which is the same rule `ProfileAjaxController::resolveOwnedProfile()`
 * applies when a payload carries no `uid` — the two must agree, because the
 * component sends the uid it was rendered with back to the endpoints.
 *
 * An anonymous visitor is **not** an error here. The plugin sits on a page a
 * site may link from anywhere, and a 403 or an exception page for "you are not
 * logged in" is a worse answer than a sentence saying so. The authorization
 * boundary is on the write endpoints and is unaffected by what this action
 * renders — see `docs/frontend-edit/authorization.md`.
 *
 * ## Why the plugin is registered non-cacheable
 *
 * The markup carries a request token signed with a **per browser** nonce, while
 * the TYPO3 page cache identifier varies by frontend user *group* ids rather
 * than by user uid. A cached rendering would hand user B the token — and the
 * profile — of user A. `ext_localconf.php` therefore lists the action as
 * non-cacheable, which makes the plugin `USER_INT` and removes the question.
 *
 * Assets survive that: `<f:asset.module>` and `<f:asset.css>` are collected
 * during the non-cached pass and rendered into the placeholders of the cached
 * page by `PageRenderer::renderJavaScriptAndCssForProcessingOfUncachedContentObjects()`
 * (`cms-frontend/Classes/Http/RequestHandler.php:300-307`), which re-runs the
 * whole JavaScript and CSS rendering — the import map included.
 */
final class ProfileEditController extends ActionController
{
    /**
     * The language file every user facing string of this plugin comes from,
     * including the ones that are handed to the component as data rather than
     * rendered as markup.
     */
    private const LANGUAGE_FILE = 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf';

    /**
     * The endpoints the component calls, in the order they are documented.
     *
     * `read` is deliberately absent, and its absence is a contract:
     * `api/endpoints.ts` refuses a map that is missing one of these six and
     * accepts one that carries nothing else, because the initial state is
     * rendered into the markup and every write answers with the whole
     * aggregate. A component that could read separately would have a second way
     * to learn the truth, and two ways is one too many.
     *
     * @var list<string>
     */
    private const ENDPOINT_ACTIONS = [
        'save',
        'saveField',
        'addChild',
        'removeChild',
        'reorderChildren',
        'setChildVisibility',
        'uploadImage',
        'removeImage',
    ];

    /**
     * Every label key the component looks up, and therefore every key that has
     * to exist in {@see LANGUAGE_FILE}.
     *
     * The list is written out rather than derived, because it is the contract
     * between two languages: `model/labels.ts` builds these strings from a
     * scope and a field name at runtime, so nothing in the TypeScript build can
     * notice a key that was never translated. Spelling them here makes the
     * contract one `grep` wide in both directions — the keys in this array are
     * the `id` attributes in the XLIFF file, letter for letter.
     *
     * A key that is missing anyway is not fatal: `label()` answers with the key
     * itself, so the surface shows `action.save` instead of a blank button.
     *
     * @var list<string>
     */
    private const LABEL_KEYS = [
        // Field labels, `field.<scope>.<name>` — fieldLabelKey().
        'field.profile.shortname',
        'field.profile.firstname',
        'field.profile.lastname',
        'field.profile.birthday',
        'field.profile.bio',
        'field.address.type',
        'field.address.line1',
        'field.address.line2',
        'field.email.type',
        'field.email.email',
        'field.profile.image',
        // Select item labels, `choice.<scope>.<field>.<value>` — choiceLabelKey().
        'choice.address.type.home',
        'choice.address.type.work',
        'choice.address.type.others',
        'choice.email.type.private',
        'choice.email.type.business',
        'choice.email.type.others',
        // Buttons, `action.<name>` — actionLabelKey().
        'action.edit',
        'action.apply',
        'action.cancel',
        'action.editRecord',
        'action.save',
        'action.add',
        'action.remove',
        'action.moveUp',
        'action.moveDown',
        'action.hide',
        'action.show',
        'action.chooseImage',
        'action.replaceImage',
        // Section headings, `section.<scope>` — sectionLabelKey().
        'section.address',
        'section.email',
        // Record states the surface shows and cannot change — stateLabelKey().
        'state.hidden',
        // The alternative text of the image. The component substitutes the name
        // itself rather than taking the rendered string, so the alt text follows
        // a name the visitor has just changed without a round trip for it.
        'profile.image.alt',
        // Shown when an upload was refused. Nothing is moved into storage on a
        // rejected upload, so the file really does have to be chosen again — the
        // surface must not look as though it still holds it.
        'error.imageNotStored',
        // The sentence shown for a failure that is not a validation failure.
        // Only `error.request` has to exist; the two status specific ones are
        // the failures a user can act on, and `requestErrorText()` prefers them
        // when they are present.
        'error.request',
        'error.request.403',
        'error.request.409',
    ];

    /**
     * How the three JSON attributes are encoded.
     *
     * The `JSON_HEX_*` flags are the point of this constant and the reason it
     * is not the one {@see \SBUERK\ModernExtbaseFrontendEdit\Http\JsonEnvelope}
     * uses: that document is served as `application/json` and read with
     * `Response.json()`, while this one is **embedded in an HTML attribute**.
     *
     * What they buy is precise, and it is worth stating precisely rather than
     * generously. Fluid escapes `{profileJson}` with
     * `htmlspecialchars($value, ENT_QUOTES)` (`EscapingNode.php:46`), and that
     * *is* what makes the attribute well formed — the structural double quotes
     * of the JSON document itself are not string content and no encoding flag
     * touches them, so the emitted attribute genuinely carries `&quot;` and the
     * browser decodes it on the way back. The flags cover the other half: no
     * value can contribute a `<`, `>`, `&`, `'` or `"` of its own, so the
     * document holds together as an attribute even where the escaping is not in
     * play — a `f:format.raw` somebody adds later, an inspection in the browser,
     * a `</script>` inside a biography.
     */
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    public function __construct(
        private readonly ProfileOwnershipResolverInterface $profileOwnershipResolver,
        private readonly AddressEditRepository $addressEditRepository,
        private readonly EmailEditRepository $emailEditRepository,
        private readonly ProfileDocumentFactory $profileDocumentFactory,
        private readonly Context $context,
        private readonly WorkspaceGuard $workspaceGuard,
    ) {}

    /**
     * Renders the editable view of the caller's own profile.
     *
     * Four states, and the template branches on three booleans rather than on
     * anything it derives:
     *
     * - **anonymous** — `authenticated` is `false`, a sentence says so.
     * - **logged in without a profile** — `authenticated` is `true` and
     *   `profile` is `null`. Distinguished from the previous case on purpose:
     *   "log in" and "you have no profile yet" are different instructions.
     * - **logged in with a profile** — the custom element, carrying the four
     *   attributes, wrapped around the server rendered read view.
     * - **a workspace is active** — `writesAllowed` is `false`, and the same
     *   read view is rendered *without* the element, the assets or the four
     *   attributes, under a sentence that says editing is live only.
     *
     * The last state is not a nicety. The write endpoints refuse in a workspace
     * and always have — {@see WorkspaceGuard} explains why they must — but
     * until this branch existed the refusal arrived as a `409` after the visitor
     * had typed, which is a gap presented as a bug. What the visitor sees is now
     * what the server will do.
     *
     * It is deliberately decided **here** rather than in the template: the
     * condition is `WorkspaceGuard`'s, the same object the endpoints and the
     * persistence service ask, so the surface and the write path cannot come to
     * different conclusions about the same request.
     *
     * The collections are read through the **edit** repositories and not off
     * `$profile->getAddresses()`. Relations are reconstituted with query
     * settings built from scratch (`DataMapper::getPreparedQuery()`), so the
     * parent's collection never contains the records the owner has hidden —
     * which are exactly the records this plugin exists to let the owner see and
     * publish again.
     *
     * Both collections are then handed to {@see ProfileDocumentFactory}, the one
     * producer of the document the component reads — the same one every endpoint
     * response goes through, so the attribute rendered here and the body of the
     * first successful save cannot disagree. The factory does not resolve the
     * children itself precisely so that this choice stays visible at the two
     * lines above: it is a visibility decision, and it is this controller's.
     */
    public function editAction(): ResponseInterface
    {
        $frontendUserId = $this->resolveFrontendUserId();
        $profile = $frontendUserId === 0 ? null : $this->resolveOwnedProfile($frontendUserId);
        $writesAllowed = $this->workspaceGuard->areWritesAllowed();

        $this->view->assignMultiple([
            'authenticated' => $frontendUserId > 0,
            'profile' => $profile,
            'writesAllowed' => $writesAllowed,
        ]);

        if ($profile === null) {
            return $this->htmlResponse();
        }

        $profileUid = (int)$profile->getUid();
        /** @var list<Address> $addresses */
        $addresses = array_values($this->addressEditRepository->findAllByProfileUid($profileUid)->toArray());
        /** @var list<Email> $emails */
        $emails = array_values($this->emailEditRepository->findAllByProfileUid($profileUid)->toArray());

        $this->view->assignMultiple([
            'profileName' => $this->displayName($profile),
            'addresses' => $addresses,
            'emails' => $emails,
        ]);

        // Everything below exists for the component and for nothing else. In a
        // workspace there is no component, so none of it is produced: no
        // document, no endpoint map, no label map, and above all no request
        // token — issuing one commits a nonce to the session, and a token
        // handed out for a surface that cannot write is a credential nobody
        // asked for.
        if (!$writesAllowed) {
            return $this->htmlResponse();
        }

        $this->view->assignMultiple([
            'profileJson' => $this->encode($this->profileDocumentFactory->create($profile, $addresses, $emails)),
            'endpointsJson' => $this->encode($this->endpointUris()),
            'labelsJson' => $this->encode($this->labels()),
            'requestToken' => $this->issueRequestToken(),
        ]);

        return $this->htmlResponse();
    }

    /**
     * The profile of the calling session, or `null` when it owns none.
     *
     * The owned set is resolved from the session and reduced to its lowest uid.
     * This extension stores one profile per frontend user, but the resolver
     * interface allows several, so the choice is made deterministic rather than
     * left to the query order — and it is made the *same* way as in
     * `ProfileAjaxController::resolveOwnedProfile()`, because the component
     * sends the uid rendered here back to the endpoints and a disagreement
     * would answer `404` on every write.
     */
    private function resolveOwnedProfile(int $frontendUserId): ?Profile
    {
        $lowest = null;
        $lowestUid = null;
        foreach ($this->profileOwnershipResolver->resolveOwnedProfiles($frontendUserId) as $ownedProfile) {
            $ownedProfileUid = $ownedProfile->getUid();
            if ($ownedProfileUid === null) {
                continue;
            }
            if ($lowestUid === null || $ownedProfileUid < $lowestUid) {
                $lowest = $ownedProfile;
                $lowestUid = $ownedProfileUid;
            }
        }

        return $lowest;
    }

    /**
     * One finished URL per endpoint, built server side.
     *
     * A client cannot assemble these. The Extbase action travels in the query
     * string — `tx_modernextbasefrontendedit_ajax[action]=saveField` — and is
     * therefore part of the cHash, which `PageArgumentValidator` answers `404`
     * for when it is missing or wrong, and which cannot be computed in a
     * browser. Hence a map of six URLs rather than a base and an action name.
     *
     * `setTargetPageType()` rather than `setFormat('json')`: both resolve to the
     * same number through `view.formatToPageTypeMapping.json`, and the page type
     * is what the endpoint page object is keyed on, so naming it directly leaves
     * nothing to a second configuration key.
     *
     * The target page is the page the plugin sits on — the endpoints answer on
     * whichever page that is, which is why no separate page has to be created
     * for them. It is set explicitly rather than left to the `UriBuilder`, whose
     * fallback is `getTargetPidByPlugin()` for the `Ajax` plugin (a `tt_content`
     * lookup that finds nothing, since the endpoint plugin has no content
     * element) and then a request attribute.
     *
     * An `ajaxPageType` of `0` yields an empty map, which is not an oversight:
     * every URL would then point at the ordinary page type and answer HTML.
     * `parseEndpoints()` refuses a map it cannot use, the component does not
     * enhance, and the server rendered profile stays readable — which is a far
     * better failure than an editing surface whose every button answers with a
     * page.
     *
     * @return array<string, string>
     */
    private function endpointUris(): array
    {
        $pageType = (int)($this->settings['ajaxPageType'] ?? 0);
        if ($pageType <= 0) {
            return [];
        }

        $pageUid = $this->currentPageUid();
        $uris = [];
        foreach (self::ENDPOINT_ACTIONS as $action) {
            $uriBuilder = $this->uriBuilder->reset()->setTargetPageType($pageType);
            if ($pageUid > 0) {
                $uriBuilder->setTargetPageUid($pageUid);
            }
            $uris[$action] = $uriBuilder->uriFor(
                $action,
                [],
                'ProfileAjax',
                'ModernExtbaseFrontendEdit',
                'Ajax',
            );
        }

        return $uris;
    }

    /**
     * The page this plugin is rendered on.
     *
     * Read from the `routing` request attribute, which carries the
     * {@see PageArguments} the page resolver produced and which is spelled the
     * same on both target core versions — unlike the page information
     * attribute, which the `UriBuilder` falls back to internally.
     */
    private function currentPageUid(): int
    {
        $pageArguments = $this->request->getAttribute('routing');

        return $pageArguments instanceof PageArguments ? $pageArguments->getPageId() : 0;
    }

    /**
     * A request token for the writing endpoints, as a hash signed JWT.
     *
     * The scope is `ProfileAjaxController::REQUEST_TOKEN_SCOPE` and is named
     * through the constant on purpose: a scope that drifts apart from the one
     * the endpoints compare against rejects every write, and the failure then
     * looks like a broken token rather than like a typo.
     *
     * `provideSigningSecret()` is what makes the nonce cookie be emitted on this
     * response — `RequestTokenMiddleware::process()` enriches the response after
     * `$handler->handle()` (`RequestTokenMiddleware.php:71-72`), so a nonce
     * created while a `USER_INT` plugin renders still reaches the browser. This
     * is therefore not a read of an existing secret, it is the reason the token
     * can be verified at all.
     *
     * No `withMergedParams()`. `f:form` puts the form action URI in there, but
     * nothing verifies it: the middleware only reconstitutes the token, and
     * `assertRequestToken()` compares the scope and nothing else. A claim that
     * no code checks reads like a constraint and is not one — and there are six
     * endpoint URIs here anyway, so any single one of them would be arbitrary.
     *
     * An empty string when no `nonce` signing provider is registered. `f:form`
     * throws a `\LogicException` in that situation; this plugin degrades
     * instead, because the component treats an empty token as "do not enhance"
     * and the readable profile survives, whereas an exception page destroys the
     * one thing progressive enhancement promises.
     */
    private function issueRequestToken(): string
    {
        $signingProvider = SecurityAspect::provideIn($this->context)
            ->getSigningSecretResolver()
            ->findByType('nonce');
        if ($signingProvider === null) {
            return '';
        }

        return RequestToken::create(ProfileAjaxController::REQUEST_TOKEN_SCOPE)
            ->toHashSignedJwt($signingProvider->provideSigningSecret());
    }

    /**
     * Every string the component renders, translated.
     *
     * The component draws its own controls, so its text cannot come from
     * `f:translate` in a template — a literal in a TypeScript file is a string
     * no XLIFF file knows about. It is built here rather than in Fluid because
     * a thirty entry map assembled from inline array syntax is markup nobody
     * can review, and because "what the component needs" is a decision, which
     * belongs in the controller.
     *
     * A key without a translation is skipped rather than emitted empty:
     * `parseLabels()` drops empty entries anyway and `label()` then answers with
     * the key itself, which is visible in the browser instead of silently blank.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        $labels = [];
        foreach (self::LABEL_KEYS as $key) {
            $label = LocalizationUtility::translate(self::LANGUAGE_FILE . ':' . $key);
            if ($label !== null && $label !== '') {
                $labels[$key] = $label;
            }
        }

        return $labels;
    }

    /**
     * The name shown as the heading and in the image alternative text.
     *
     * The same rule `Profile/Card` applies, computed here because the template
     * of this plugin renders the heading itself: the card partial bundles the
     * image with the name, and the two have to end up on different sides of the
     * custom element — the image outside, where the component does not manage
     * it, the name inside, where it is part of the view the component replaces.
     *
     * `shortname` is the fallback because it is the only guaranteed label; a
     * profile may carry neither a first nor a last name, and without the
     * fallback the heading and the alternative text would be empty.
     */
    private function displayName(Profile $profile): string
    {
        $name = trim($profile->getFirstname() . ' ' . $profile->getLastname());

        return $name === '' ? $profile->getShortname() : $name;
    }

    /**
     * @param array<string, mixed> $data
     * @throws \JsonException
     */
    private function encode(array $data): string
    {
        if ($data === []) {
            // An empty attribute rather than "[]" or "{}": `readJson()` answers
            // `null` for it, which is what the parsers treat as "absent". "[]"
            // would decode to an array and travel one step further before being
            // refused, for no gain.
            return '';
        }

        return json_encode($data, self::JSON_FLAGS);
    }

    /**
     * The uid of the logged-in frontend user, or `0`.
     *
     * The aspect is read per call and never cached in a property: `Context` is
     * a singleton and its `frontend.user` aspect is *replaced* — by
     * `FrontendUserAuthenticator` and again by `PreviewSimulator`.
     * `isLoggedIn()` is asked first, because `get('id')` yields `0` for an
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
}
