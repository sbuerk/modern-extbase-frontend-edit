<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\Test;

/**
 * The edit plugin, rendered through a real frontend request.
 *
 * What it covers is the server half of the editing surface and nothing the
 * browser does with it: the three states the template branches on, the four
 * `data-` attributes that are the whole contract with the lit component, the
 * endpoint URLs a client cannot assemble itself, the assets a `USER_INT` plugin
 * has to get out of the non-cached pass — and the one property that ties this
 * plugin to `ProfileAjaxController`, namely that the document rendered into the
 * page is the document the endpoints answer with.
 *
 * ## Why this extends the AJAX test case
 *
 * {@see theEmbeddedDocumentIsIdenticalToTheReadEndpointDocument()} is the
 * reason. It has to fire a real request at the `read` endpoint and compare the
 * answer with the attribute, and everything that request needs — the endpoint
 * page type, the cHash calculation, the envelope helpers — lives in
 * {@see AbstractProfileAjaxTestCase}. Rebuilding it here would mean a second
 * copy of the cHash arithmetic, which is exactly the kind of duplication this
 * test exists to catch elsewhere.
 *
 * What that base class brings along is stated so it is not mistaken for an
 * accident. Its child records for the profile of the *other* frontend user are
 * kept and are what {@see theRenderedProfileIsTheOneOfTheCallingSession()} reads
 * — without them that profile would render as an empty aggregate and the test
 * would compare almost nothing. Its second storage page is **not** kept:
 * {@see setUpProfilePluginRendering()} is overridden, and says why.
 *
 * ## What the fixture adds
 *
 * `ProfileEditPlugin.csv` carries the two rows nothing else in the suite needs:
 * the `tt_content` record placing this plugin on {@see EDIT_PAGE_ID}, and a
 * third frontend user who owns no profile at all. The latter is not a detail —
 * without it, "logged in without a profile" and "not logged in" cannot be told
 * apart by any test, and the template renders two different sentences for them.
 *
 * ## What is deliberately not covered here
 *
 * That the profile image is rendered **outside** the custom element, which is
 * the one thing the template puts there because the component does not manage
 * it. No fixture profile carries a profile image, so `Profile/Image` renders
 * into an empty string and an assertion about where it is not would hold with
 * the partial gone — the same stated gap `ProfileShowPluginTest` carries, for
 * the same reason: covering it needs `sys_file` and `sys_file_reference`
 * fixtures plus a file on disk in the test instance, and that belongs with the
 * change owning the image handling.
 */
final class ProfileEditPluginTest extends AbstractProfileAjaxTestCase
{
    /**
     * The page the edit plugin sits on, as the fixture places it.
     */
    private const EDIT_URI = 'https://acme.com/edit-profile';

    /**
     * A frontend user with a session and without a profile.
     */
    private const PROFILELESS_FRONTEND_USER_ID = 3;

    /**
     * The custom element the component upgrades, as an opening tag prefix.
     *
     * Written with the `<` so it cannot accidentally match the
     * `modern-extbase-frontend-edit-profile-edit` class name of the wrapping
     * section, which every one of the three states renders.
     */
    private const ELEMENT = '<modern-extbase-frontend-edit-profile';

    /**
     * The four attributes the component reads, and all four are required:
     * `profileEdit.ts` enhances only when it can parse a profile, an endpoint
     * map and a non empty token.
     *
     * @var list<string>
     */
    private const ELEMENT_ATTRIBUTES = [
        'data-profile',
        'data-endpoints',
        'data-token',
        'data-labels',
    ];

    /**
     * The six actions the endpoint map has to carry, in order.
     *
     * `read` is deliberately absent — the initial state is rendered into the
     * markup and every write answers with the whole aggregate, so a component
     * that could read separately would have a second way to learn the truth.
     * Asserting the list rather than its size is what keeps a seventh entry
     * from appearing unnoticed.
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
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/ProfileEditPlugin.csv');
    }

    /**
     * The classic flavour of the grandparent class, with **one** storage page
     * and with `config.sendCacheHeaders` switched on.
     *
     * The storage page list is narrowed back down because nothing here needs
     * the second one — it exists in {@see AbstractProfileAjaxTestCase} to make
     * "a new child takes the pid of its parent" falsifiable, and this plugin
     * writes nothing.
     *
     * `sendCacheHeaders` is the load bearing half, and it is what makes
     * {@see theEditPluginIsRenderedAsANonCachedContentObject()} able to fail at
     * all. Without it `getClientCacheHeaders()` returns its fallback —
     * `private, no-store` — for *every* response, cacheable or not
     * (`cms-frontend/Classes/Http/RequestHandler.php:1275-1279`), so an
     * assertion on that header would hold just as happily for a plugin that is
     * cached. With it, the only thing standing between the anonymous edit page
     * and a `max-age` header is `hasNotCachedContentElements()` (`:1219`).
     */
    protected function setUpProfilePluginRendering(): void
    {
        $this->setUpFrontendRootPage(
            self::STORAGE_PAGE_ID,
            [],
            [
                'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/',
                'constants' => implode(LF, [
                    'plugin.tx_modernextbasefrontendedit.persistence.storagePid = ' . self::STORAGE_PAGE_ID,
                    'plugin.tx_modernextbasefrontendedit.settings.showPageUid = ' . self::SHOW_PAGE_ID,
                    'plugin.tx_modernextbasefrontendedit.settings.editPageUid = ' . self::EDIT_PAGE_ID,
                ]) . LF,
                'config' => self::PAGE_TYPOSCRIPT . LF . 'config.sendCacheHeaders = 1' . LF,
            ],
        );
    }

    #[Test]
    public function theOwnerGetsTheEditingElementWithAllFourAttributesFilled(): void
    {
        $body = (string)$this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)->getBody();

        $this->assertStringContainsString(self::ELEMENT, $body);

        $attributes = $this->elementAttributes($body);
        foreach (self::ELEMENT_ATTRIBUTES as $name) {
            $this->assertArrayHasKey($name, $attributes, $name . ' is not rendered');
            $this->assertNotSame('', $attributes[$name], $name . ' is rendered empty');
        }

        $profile = $this->decode($attributes['data-profile']);
        $this->assertSame(self::OWNED_PROFILE_UID, $profile['uid']);
        $this->assertSame('ada', $profile['shortname']);
        $this->assertSame('Ada', $profile['firstname']);
        $this->assertSame('Lovelace', $profile['lastname']);
        // The DTO's wire format, so what is read is spelled like what may be
        // written back.
        $this->assertSame('1980-05-17', $profile['birthday']);
        $this->assertFalse($profile['hidden']);

        // The token is a hash signed JWT, not a placeholder. Only the shape is
        // asserted: the signature is verified by the endpoint tests, which is
        // where a forged one is supposed to be refused.
        $this->assertMatchesRegularExpression('#^ey[A-Za-z0-9_-]+\.#', $attributes['data-token']);

        // A representative key of each of the four label families the component
        // builds at runtime. `model/labels.ts` composes these strings from a
        // scope and a field name, so nothing in the TypeScript build can notice
        // a key that was never translated.
        $labels = $this->decode($attributes['data-labels']);
        $this->assertSame('Short name', $labels['field.profile.shortname']);
        $this->assertSame('Home', $labels['choice.address.type.home']);
        $this->assertSame('Save all fields', $labels['action.save']);
        $this->assertSame('Addresses', $labels['section.address']);
        $this->assertSame('Hidden', $labels['state.hidden']);
        $this->assertArrayHasKey('error.request', $labels);
    }

    /**
     * The one assertion that separates the edit view from the read view.
     *
     * The owner's hidden address is absent from `$profile->getAddresses()` —
     * relations are reconstituted with query settings built from scratch
     * (`DataMapper::getPreparedQuery()`) — and present in the finder of
     * `AddressEditRepository`. It is the record the plugin exists to let the
     * owner see and publish again, so a document without it is not a smaller
     * document, it is the wrong one.
     */
    #[Test]
    public function theEmbeddedDocumentCarriesTheOwnersHiddenChildren(): void
    {
        $body = (string)$this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)->getBody();
        $profile = $this->decode($this->elementAttributes($body)['data-profile']);

        // Stored sorting order, hidden ones included — the order the endpoints
        // read and the reordering endpoint writes.
        $this->assertSame(self::OWNED_ADDRESS_UIDS, array_column($profile['addresses'], 'uid'));
        $this->assertSame(self::OWNED_EMAIL_UIDS, array_column($profile['emails'], 'uid'));

        // Uid 4 is the hidden one, and the document says so rather than merely
        // containing it: the surface renders the state and offers to publish it.
        $this->assertSame(
            [2 => false, 3 => false, 1 => false, 4 => true],
            array_column($profile['addresses'], 'hidden', 'uid'),
        );
        $this->assertSame(
            'Hidden Alley 4',
            array_column($profile['addresses'], 'line1', 'uid')[4],
        );
    }

    /**
     * A client cannot assemble these URLs.
     *
     * The Extbase action travels in the query string and is therefore part of
     * the cHash, which `PageArgumentValidator` answers `404` for when it is
     * missing or wrong and which cannot be computed in a browser. The page type
     * is the second half: without it every URL points at the ordinary page type
     * and every button answers with an HTML page.
     */
    #[Test]
    public function everyEndpointUrlCarriesTheEndpointPageTypeAndACacheHash(): void
    {
        $body = (string)$this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)->getBody();
        $endpoints = $this->decode($this->elementAttributes($body)['data-endpoints']);

        $this->assertSame(self::ENDPOINT_ACTIONS, array_keys($endpoints));

        foreach ($endpoints as $action => $uri) {
            $this->assertIsString($uri);
            $this->assertStringContainsString('type=' . self::AJAX_PAGE_TYPE, $uri, $action);
            $this->assertStringContainsString('cHash=', $uri, $action);
            $this->assertStringContainsString('%5Bcontroller%5D=ProfileAjax', $uri, $action);
            $this->assertStringContainsString('%5Baction%5D=' . $action, $uri, $action);
            // The endpoints answer on the page the plugin sits on, which is why
            // no separate page has to be created for them.
            $this->assertStringStartsWith('/edit-profile', $uri, $action);
        }
    }

    #[Test]
    public function anAnonymousVisitorGetsTheLoginSentenceAndNoElement(): void
    {
        $body = (string)$this->renderUri(self::EDIT_URI)->getBody();

        $this->assertStringContainsString('You are not logged in.', $body);
        $this->assertStringNotContainsString('There is no profile assigned to your account yet.', $body);
        $this->assertStringNotContainsString(self::ELEMENT, $body);
        // Nothing on the page for the module to enhance, so it is not loaded.
        $this->assertStringNotContainsString('frontend-edit.js', $body);
    }

    /**
     * The second empty state, and the reason it is a state of its own: "log in
     * first" and "you have no profile yet" are different instructions, and one
     * sentence covering both would be actionable for neither visitor.
     */
    #[Test]
    public function aLoggedInUserWithoutAProfileGetsTheOtherSentenceAndNoElement(): void
    {
        $body = (string)$this->renderUri(self::EDIT_URI, self::PROFILELESS_FRONTEND_USER_ID)->getBody();

        $this->assertStringContainsString('There is no profile assigned to your account yet.', $body);
        $this->assertStringNotContainsString('You are not logged in.', $body);
        $this->assertStringNotContainsString(self::ELEMENT, $body);
        $this->assertStringNotContainsString('frontend-edit.js', $body);
    }

    /**
     * The plugin is registered non-cacheable, so Extbase converts it to a
     * `USER_INT` object and its output is never written to the page cache.
     *
     * The assertion is made **anonymously**, and that is the only way it can
     * carry any information: `getClientCacheHeaders()` refuses client caching
     * for a logged-in frontend user on its own
     * (`RequestHandler.php:1221`), so the owner's response says `no-store`
     * whether or not this plugin is cached. For a visitor without a session the
     * three other conditions of `$clientCachingPossible` all hold, the site
     * sends cache headers (see {@see setUpProfilePluginRendering()}), and
     * `hasNotCachedContentElements()` is the single remaining reason the page
     * does not answer with a `max-age` — which is exactly the property under
     * test.
     *
     * That the action renders a sentence rather than the editing surface for
     * this visitor is beside the point: the registration is per action, so the
     * whole plugin is a non-cached content object in all three of its states.
     */
    #[Test]
    public function theEditPluginIsRenderedAsANonCachedContentObject(): void
    {
        $cacheControl = $this->renderUri(self::EDIT_URI)->getHeaderLine('Cache-Control');

        $this->assertSame('private, no-store', $cacheControl);
        $this->assertStringNotContainsString('max-age', $cacheControl);
    }

    /**
     * What the previous test buys: the record comes from the session, and two
     * sessions get two documents.
     *
     * There is no `profile` argument to this plugin, so nothing a visitor sends
     * can select the record — but "the session decides" is only true as long as
     * the session is actually read, and a plugin that resolved the profile once
     * and served it to everyone would look correct in every single user test.
     * Two users in one test is what makes that falsifiable.
     *
     * The tokens are compared for the same reason and are a second, independent
     * signal: each is signed with a per browser nonce, so two renderings that
     * shared one token would be one rendering handed out twice.
     *
     * **What this does not assert.** That a *cached* page cannot leak the first
     * user's markup to the second — the functional test instance configures the
     * `pages` cache with a `NullBackend`
     * (`FunctionalTestCase.php:419`), so no sub-request here is ever served from
     * the page cache and no test in this harness can observe that. The property
     * is real and is the reason the plugin is registered non-cacheable; what
     * covers it is {@see theEditPluginIsRenderedAsANonCachedContentObject()},
     * which asserts the registration itself.
     */
    #[Test]
    public function theRenderedProfileIsTheOneOfTheCallingSession(): void
    {
        $owner = $this->elementAttributes(
            (string)$this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)->getBody(),
        );
        $other = $this->elementAttributes(
            (string)$this->renderUri(self::EDIT_URI, self::OTHER_FRONTEND_USER_ID)->getBody(),
        );

        $this->assertSame(self::OWNED_PROFILE_UID, $this->decode($owner['data-profile'])['uid']);
        $this->assertSame(self::FOREIGN_PROFILE_UID, $this->decode($other['data-profile'])['uid']);
        $this->assertNotSame(
            $owner['data-token'],
            $other['data-token'],
            'The request token is signed with a per browser nonce and must not be shared.',
        );
    }

    /**
     * The stylesheet and the module, emitted from a `USER_INT` plugin.
     *
     * `<f:asset.css>` and `<f:asset.module>` are collected while the non-cached
     * pass renders, and
     * `PageRenderer::renderJavaScriptAndCssForProcessingOfUncachedContentObjects()`
     * (`cms-frontend/Classes/Http/RequestHandler.php:300-307`) re-runs the whole
     * JavaScript and CSS rendering into the placeholders of the page — the
     * import map included, which is what the module needs to resolve
     * `@sbuerk/modern-extbase-frontend-edit/frontend-edit.js` at all. A leftover
     * `<!-- ###JS_LIBS` marker in the body is what that step failing looks like,
     * and it is asserted because a missing tag and an unsubstituted placeholder
     * are different defects.
     */
    #[Test]
    public function theAssetsOfTheEditingSurfaceAreEmitted(): void
    {
        $body = (string)$this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)->getBody();

        $this->assertStringContainsString('<script type="importmap"', $body);
        $this->assertStringContainsString(
            '@sbuerk/modern-extbase-frontend-edit/frontend-edit.js',
            $body,
        );
        $this->assertStringContainsString(
            'modern_extbase_frontend_edit/Resources/Public/Css/frontend-edit.css',
            $body,
        );
        $this->assertStringNotContainsString('<!-- ###JS_LIBS', $body);
    }

    /**
     * The view a visitor keeps when the module never loads.
     *
     * It sits **inside** the element, which is an unknown tag with children
     * until it upgrades and does not slot them afterwards — so what is asserted
     * is not that the page contains these strings, but that they are within the
     * element. The heading is part of it for a reason: the component edits the
     * name, and a heading left outside would still show the value the page was
     * loaded with after the first save.
     *
     * The hidden address is expected here as well. The no-JavaScript view of the
     * *owner* is the editing view without the editing, and a record the owner
     * hid is one the owner has to be able to find again.
     */
    #[Test]
    public function theServerRenderedProfileSitsInsideTheElement(): void
    {
        $body = (string)$this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)->getBody();
        $inside = $this->elementContent($body);

        $this->assertStringContainsString('Ada Lovelace', $inside);
        $this->assertStringContainsString('Difference Engine Road 1', $inside);
        $this->assertStringContainsString('Hidden Alley 4', $inside);
        $this->assertStringContainsString('Hidden', $inside);
        $this->assertStringContainsString('first@example.org', $inside);
    }

    /**
     * The document embedded in the page and the document the `read` endpoint
     * answers with are the same document.
     *
     * This is the reason both are built by one factory, and it is the assertion
     * that keeps them that way. The component replaces its state wholesale with
     * the `data` object of the next successful response, so a page that embeds
     * a *different* shape produces a surface which changes on the first save —
     * a field that visibly reverts after a write the server accepted, which is
     * a bad failure to debug and invisible in a review of either side alone.
     *
     * The comparison is made on the decoded documents rather than on the two
     * JSON strings, and that is deliberate: the encoding flags differ on
     * purpose. The attribute is embedded in HTML and is encoded with the
     * `JSON_HEX_*` flags, the response is served as `application/json` and is
     * not. `assertSame()` on the decoded arrays is still strict about key
     * order and value types, which is the part that has to agree.
     */
    #[Test]
    public function theEmbeddedDocumentIsIdenticalToTheReadEndpointDocument(): void
    {
        $body = (string)$this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)->getBody();
        $embedded = $this->decode($this->elementAttributes($body)['data-profile']);

        // No request token: `read` changes nothing and requires none.
        $response = $this->sendAjaxRequest(
            action: 'read',
            payload: ['uid' => self::OWNED_PROFILE_UID],
            frontendUserId: self::OWNER_FRONTEND_USER_ID,
            requestToken: self::TOKEN_ABSENT,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->successData($response), $embedded);
    }

    /**
     * The `data-` attributes of the custom element, decoded back out of the
     * HTML escaping Fluid applied.
     *
     * That escaping is what makes the attribute well formed in the first place:
     * the structural double quotes of the JSON document are turned into
     * `&quot;`, and the browser decodes them on the way back. This helper is the
     * test side of the same step.
     *
     * @return array<string, string>
     */
    private function elementAttributes(string $body): array
    {
        $this->assertSame(
            1,
            preg_match('#' . preg_quote(self::ELEMENT, '#') . '\s(.*?)>#s', $body, $matches),
            'The custom element is not rendered.',
        );
        $this->assertGreaterThan(
            0,
            preg_match_all('#(data-[a-z]+)="([^"]*)"#s', $matches[1], $found, PREG_SET_ORDER),
            'The custom element carries no data attribute at all.',
        );

        $attributes = [];
        foreach ($found as $entry) {
            $attributes[$entry[1]] = html_entity_decode($entry[2], ENT_QUOTES, 'UTF-8');
        }

        return $attributes;
    }

    /**
     * Everything the element wraps: the server rendered read view.
     */
    private function elementContent(string $body): string
    {
        $this->assertSame(
            1,
            preg_match(
                '#' . preg_quote(self::ELEMENT, '#') . '\s.*?>(.*?)</modern-extbase-frontend-edit-profile>#s',
                $body,
                $matches,
            ),
            'The custom element has no content.',
        );

        return $matches[1];
    }

    /**
     * One decoded attribute, asserting on the way that it is a JSON object.
     *
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
