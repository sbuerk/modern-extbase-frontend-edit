# AJAX transport

The frontend editing endpoints are **a dedicated page `typeNum` that renders one
`EXTBASEPLUGIN` content object**, with `config.disableAllHeaderCode = 1`, and
Extbase actions that return `$this->jsonResponse(...)`. Not eID, and not a
bespoke PSR-15 middleware.

This page records why, what the decision costs, and the request/response
contract that follows from it. The implementation lands in a later change; what
is fixed here is the design.

Line references point into the installed dependency set below
`.Build/vendor/`, which is TYPO3 v14 unless a v13 file is named explicitly.
The v13 counterparts were read from the 13.4 artifact and are noted where they
differ.

## The decision

```typoscript
modernExtbaseFrontendEditAjax = PAGE
modernExtbaseFrontendEditAjax {
    typeNum = 1589
    config {
        disableAllHeaderCode = 1
        disableLanguageHeader = 1
        admPanel = 0
        debug = 0
    }
    10 =< tt_content.modernextbasefrontendedit_ajax
}
plugin.tx_modernextbasefrontendedit.view.formatToPageTypeMapping.json = 1589
```

Three properties of this construction carry the decision:

- **`disableAllHeaderCode = 1` returns the body content unchanged**, skipping
  every `PageRenderer` setting, so the response is exactly what the plugin
  produced — `RequestHandler.php:258-262`. Honoured identically on both
  versions.
- **`EXTBASEPLUGIN` is still the registered content object** in v14
  (`cms-extbase/Configuration/Services.yaml:41`), so the plugin runs through the
  Extbase bootstrap rather than through a `userFunc`. That matters on v14:
  `#[AsAllowedCallable]` is required for any callable referenced from TypoScript
  since 14.0 (Breaking #108054), and the attribute does not exist on v13.
  `EXTBASEPLUGIN` sidesteps the split entirely.
- **`Content-Type` survives page assembly.** `jsonResponse()` sets it
  (`ActionController.php:921-926`), and the bootstrap hands it to `PageParts`
  (`Bootstrap.php:168-173`), from where `RequestHandler` writes it onto the PSR-7
  response.

### Caching

Every writing action is registered in the `$nonCacheableControllerActions`
argument of `ExtensionUtility::configurePlugin()`. The bootstrap then converts
the object to `USER_INT` *before* the action runs
(`Bootstrap.php:143-151`), so nothing the endpoint produces is ever written to
a cache.

Where PHP has to suppress the page cache beyond that, it goes through the
**cache instruction request attribute** introduced by Feature #102628 in v13.0:

```php
$request->getAttribute('frontend.cache.instruction')
    ->disableCache('EXT:modern_extbase_frontend_edit: <reason>');
```

Not `TypoScriptFrontendController->no_cache` and not `set_no_cache()`. Both were
marked read-only/internal in 13.0 (Breaking #102621, which names
`frontend.cache.instruction` as the replacement) and the whole class is gone in
14.0 (Breaking #107831). The TypoScript `config.no_cache = 1` is not deprecated,
but it is only a front for the same attribute
(`PrepareTypoScriptFrontendRendering.php:261-264`) and it is a blunt page-wide
toggle — the plugin-level registration is the more precise instrument, so the
`PAGE` object does not set it.

The page cache identifier is keyed on the frontend user's group ids
(`PrepareTypoScriptFrontendRendering.php:322-344`), not on the user. That is a
real leak vector for per-user markup, and it is closed here only because the
endpoint page type is never cached in the first place.

## Why not eID

The frontend middleware order was computed by feeding every
`Configuration/RequestMiddlewares.php` of both trees through the same
`DependencyOrderingService` that `MiddlewareStackResolver` uses
(`MiddlewareStackResolver.php:128`, `:139-140`). The relevant slice is identical
on v13.4 and v14.3:

| #  | Middleware                 | What it adds                                                              |
|----|----------------------------|---------------------------------------------------------------------------|
| 4  | `site`                     | `site`, `language`, `routing` (a `SiteRouteResult`)                       |
| 5  | `eid`                      | dispatches and **returns**                                                |
| 7  | `request-token-middleware` | received request token on the `SecurityAspect`                            |
| 9  | `authentication`           | `frontend.user` attribute and Context aspect                              |
| 15 | `page-argument-validator`  | cHash validation                                                          |
| 18 | `prepare-tsfe-rendering`   | `frontend.typoscript`, `frontend.page.information`, `frontend.page.parts` |

`EidHandler` short-circuits on `$_GET['eID']`/`$_POST['eID']` and **never calls
`$handler->handle()`** on that path (`EidHandler.php:43-65`). At position 5 that
means: no authenticated frontend user (position 9), no verified request token
(position 7), no TypoScript, no Extbase. Every one of those would have to be
rebuilt by hand, which is precisely where security bugs live.

eID is neither deprecated nor removed in 12.x, 13.x or 14.x — the only related
changelog entry is the removal of the `requirejs` entry point
(`Changelog/13.0/Breaking-100963-DeprecatedFunctionalityRemoved.rst:356-358`).
It is rejected on capability, not on status. `EidHandler` itself carries
`@internal` and recommends a PSR-15 middleware instead
(`EidHandler.php:28-32`).

## Why not a PSR-15 middleware

This is the option that looks viable and is not, so the reasoning is written out
far enough to be re-derived from the source on disk.

A middleware ordered `after: typo3/cms-frontend/authentication` does get the
frontend user and the verified request token. What it does not get is anything
`prepare-tsfe-rendering` produces — and the failure mode is not a clean
"unsupported", it is an uncaught exception at the first repository call:

1. `QueryFactory::create()` asks the configuration manager for
   `persistence.storagePid` and catches **only**
   `NoServerRequestGivenException` (`QueryFactory.php:56-65`, v13 identical).
   The comment there says the catch exists so the persistence layer can run
   without a request, in CLI or tests.
2. In a middleware there *is* a request.
   `ConfigurationManager::getConfiguration()` falls back to
   `$GLOBALS['TYPO3_REQUEST']` when none was set explicitly
   (`ConfigurationManager.php:76-78`), and in a frontend middleware that global
   is always populated. So `NoServerRequestGivenException` is never thrown, the
   frontend branch runs, and the catch block is dead.
3. `FrontendConfigurationManager::getTypoScriptSetup()` requires the
   `frontend.typoscript` request attribute and throws `\RuntimeException`
   `1700841298` when it is absent
   (`FrontendConfigurationManager.php:104-114`).
4. Nothing catches that exception. The middleware dies on the first
   `$repository->findBy…()`.

Ordering the middleware *after* `prepare-tsfe-rendering` instead does not
rescue it: that middleware only runs for a resolved page, it acquires page cache
locks and builds TypoScript on the way, and it is explicitly annotated
`@internal this middleware might get removed later`
(`PrepareTypoScriptFrontendRendering.php:68`). Depending on its side effects
from outside the rendering chain is not a contract we can rely on. Independently
of that, `Bootstrap::handleFrontendRequest()` dereferences
`frontend.page.parts` unconditionally (`Bootstrap.php:168-173`), so the Extbase
bootstrap cannot be driven from a middleware at all.

**Where a middleware is still right:** a read-only, session-free, high-volume
endpoint — a typeahead, say. Ordered after `authentication` it has the frontend
user and the token, returns `new JsonResponse([...])` directly, and costs far
less than a page render. It just cannot host Extbase. Worth naming as the
documented alternative; not worth building for the editing endpoints.

## What the decision costs

### POST for everything

`RequestBuilder` merges body parameters **only** for `POST` — `PUT`, `PATCH`
and `DELETE` bodies are ignored outright (`RequestBuilder.php:91-99`). The
request token middleware accepts `POST`, `PUT`, `PATCH`
(`RequestTokenMiddleware.php:47`). The intersection is `POST`, so every
endpoint is `POST`, including the per-field inline save. No REST verb purity;
the verb carries no information here anyway, because the action is already in
the URL.

### A JSON body is invisible to Extbase

TYPO3 fills the parsed body from `$_POST`, plus urlencoded `PUT`/`PATCH`/
`DELETE` (`ServerRequestFactory.php:99-104`). With
`Content-Type: application/json` there is no `$_POST`, `getParsedBody()` is
`null`, and every Extbase argument is missing.

The alternative — form-urlencoded payloads under the plugin namespace, mapped
by Extbase — was rejected: it buys Extbase validators, which we are not using
either — rules are data, not attributes, see
[DTOs and validation](dto-and-validation.md) — and it costs nested-array
encoding on the JavaScript side.

So the action reads `$this->request->getBody()`, decodes with
`JSON_THROW_ON_ERROR`, hydrates the DTO and validates explicitly. Extbase still
supplies controller/action routing, DI, the frontend user, `plugin.tx_*`
settings and the response. **Say this out loud in review:** Extbase's property
mapping and validator machinery never sees the payload, so nothing in the
framework is guarding these fields. Our code is.

### URLs are generated server-side

`PageArgumentValidator` returns a 404 for dynamic query arguments without a
valid cHash (`PageArgumentValidator.php:96-102`). Any
`tx_modernextbasefrontendedit_…[controller|action]` in the query string
therefore needs one, and hand-assembling the URL in JavaScript cannot produce
it.

Endpoint URLs are built with the Extbase `UriBuilder`, either
`->setTargetPageType(1589)` or `->setFormat('json')` with
`view.formatToPageTypeMapping` (`UriBuilder.php:533-542`,
`ExtensionService.php:237-244`), and handed to the component as a `data-*`
attribute. The POST body is not part of the cHash, so the payload is free.

### Status codes

Only responses `>= 300` reach `ResponseData` from an Extbase frontend plugin
(`Bootstrap.php:175-186`); anything below is flattened to the page's `200`. A
`201 Created` would silently become `200`. Where a specific status is needed,
the action throws:

```php
throw new PropagateResponseException(new JsonResponse([...], 422), 1712345678);
```

which bypasses page assembly and is caught by the outermost
`response-propagation` middleware (`ResponsePropagation.php:31-40`).

`throwStatus()` is deliberately not used on the failure paths: it writes a
plain-text body (`ActionController.php:811-819`), and a client that always
expects JSON should always get JSON.

Validation failures are **`422`, not `400`**. `400` is Extbase's own
`errorAction()` status, and the bootstrap clears the page cache for it
(`Bootstrap.php:164-166`, when `persistence.enableAutomaticCacheClearing` is on
— `Bootstrap.php:223-232`). A user mistyping a field must not evict the page
cache.

| Situation                                     | Status | Reason                                                         |
|-----------------------------------------------|--------|----------------------------------------------------------------|
| Read or write succeeded                       | `200`  | the only sub-300 status a frontend plugin can produce          |
| Malformed JSON, unknown field, wrong type     | `400`  | genuinely malformed request; matches Extbase's `errorAction()` |
| No or invalid request token, or not logged in | `403`  | matches the v14 `#[Authorize]` denial path                     |
| Record absent, or not visible to this user    | `404`  | deliberately not distinguished from "forbidden"                |
| Well-formed request, domain validation failed | `422`  | the field-level case, distinguishable from `400` by the client |
| Rate limit exceeded                           | `429`  | v14 `#[RateLimit]` only; no equivalent on v13                  |

### The editing plugin is `USER_INT`

The request token is signed with a per-browser nonce, so markup containing one
can never be page-cached — user B would otherwise receive user A's token and
every write would be rejected. The plugin that renders the editable record is
therefore non-cacheable, which is exactly what core does for felogin (its
`configurePlugin()` call lists every action in both arrays).

The alternative — cacheable markup plus a `tokenAction` round trip to fetch a
token — was rejected for the extra request and for the operational sharpness of
the nonce pool: size 5, 900 s expiry (`NoncePool.php:25-33`), so a long editing
session or a sixth tab starts evicting nonces.

## The JSON contract

Requests carry `Content-Type: application/json` and the token header. Both
endpoints are `POST` to the same `typeNum` URL, distinguished by the Extbase
action in the query string.

**Full save** — every editable property of one record:

```json
{
  "uid": 42,
  "data": {
    "firstName": "Ada",
    "lastName": "Lovelace",
    "birthday": "1815-12-10",
    "bio": ""
  }
}
```

**Partial save** — one field, for the inline editor. `field` must be a name the
DTO's rule set knows; the rule set is the single whitelist for both "what is
validated" and "what may be addressed partially":

```json
{
  "uid": 42,
  "field": "firstName",
  "value": "Ada"
}
```

**Success**, `200`:

```json
{
  "data": {
    "uid": 42,
    "firstName": "Ada",
    "lastName": "Lovelace",
    "birthday": "1815-12-10",
    "bio": ""
  }
}
```

`data` is the persisted state as the server sees it after the write, not an echo
of the request — a client that trusts its own optimistic update will drift.

> [!NOTE]
> **Concurrent editing is not designed.** Two sessions editing the same record
> overwrite each other, last write wins, and neither is told. Adding optimistic
> locking later means a record version in the response and a conflict status on
> the write — deliberately left out here rather than reserved half-way, because
> a field nobody validates is worse than an absent one.

**Validation failure**, `422`:

```json
{
  "errors": [
    { "field": "firstName", "code": 1712345678, "message": "Must not be empty." },
    { "field": "birthday",  "code": 1712345679, "message": "Must be a valid date." }
  ]
}
```

**Everything else**, `403`/`404` — same envelope, no field context:

```json
{
  "errors": [
    { "code": 1712345680, "message": "Request token missing or invalid." }
  ]
}
```

Rules the endpoint holds to:

- **A JSON body on every path, including failures.** A frontend error response
  is otherwise HTML from `ErrorController`, which the client cannot parse.
- **`code` is a TYPO3-style unix-timestamp exception code**, so an error the
  user reports is greppable to one line of PHP. The repository's
  `checkExceptionCodes` gate keeps them unique.
- **`message` is for developers.** Localised user-facing text is the client's
  job; the endpoint has no business deciding presentation.
- **Never echo back what was refused.** No reflected input in error messages.

## CSRF: the request token

`RequestToken` was introduced in v12.0 (Feature #97305) with the frontend as an
explicit target, and the companion breaking change defines the scope
`core/user-auth/fe` for frontend authentication. Core still uses it in the
frontend today — felogin creates one in its login controller and `f:form`
renders it — and nothing in 13.x or 14.x changes any of it.

Transport is the **`X-TYPO3-RequestToken` header**
(`RequestToken.php:29-30`). The header path is method-independent and, decisive
here, works with a JSON body: the `__RequestToken` body parameter is read from
`getParsedBody()` (`RequestTokenMiddleware.php:105-123`), which is `null` for
`application/json`. The signing secret is a per-browser nonce in a
`SameSite=Strict` cookie (`RequestTokenMiddleware.php:44-45`, `:133-143`).

Issuing mirrors what `FormViewHelper` does (`FormViewHelper.php:447-464`), with
`Context` injected rather than fetched from `GeneralUtility::makeInstance()`:

```php
$securityAspect = SecurityAspect::provideIn($this->context);
$signingSecret = $securityAspect->getSigningSecretResolver()
    ->findByType('nonce')
    ->provideSigningSecret();
$jwt = RequestToken::create('modern_extbase_frontend_edit/record-save')
    ->withMergedParams(['request' => ['uri' => $endpointUri]])
    ->toHashSignedJwt($signingSecret);
```

`provideSigningSecret()` is what causes the nonce cookie to be emitted on that
response, which is why it must happen while rendering the editable markup.

Validation on the endpoint reads the three-state property documented on the
aspect itself (`SecurityAspect.php:31-35`):

| `getReceivedRequestToken()`         | Meaning                          | Response |
|-------------------------------------|----------------------------------|----------|
| `null`                              | no token was sent                | `403`    |
| `false`                             | a token was sent and was invalid | `403`    |
| `RequestToken` with a foreign scope | valid token, wrong scope         | `403`    |
| `RequestToken` with our scope       | accepted                         | continue |

The nonce is **not** revoked after a successful call. Single-use tokens are
hostile to an inline per-field editor, which saves many times per page view.

Two things stated plainly rather than buried:

- **The token is proof of visit, not authorisation.** It shows that this browser
  loaded our page recently. It says nothing about who the actor is or whether
  they may edit this record. Authentication (a logged-in `fe_users` record) and
  authorisation (our own per-record rule) are separate and both mandatory —
  see [authorization](authorization.md).
- **`RequestToken` and `SecurityAspect` are `@internal`**
  (`RequestToken.php:22-25`, `SecurityAspect.php:26-29`). They are reached from
  public API through `f:form`'s `requestToken` argument, and felogin uses them
  from PHP, but calling `RequestToken::create()` from extension code is
  officially internal. This is a knowing trade-off: the alternative is a
  home-grown CSRF token, and a home-grown one that is wrong is worse than a core
  one that is `@internal`. If the API changes, the breakage is loud and local
  to two classes.

## Content Security Policy

Frontend CSP is **opt-in and off by default on both versions**:

```php
// .Build/vendor/typo3/cms-core/Configuration/DefaultConfiguration.php:110-111
'security.frontend.enforceContentSecurityPolicy' => false,
'security.frontend.reportContentSecurityPolicy' => false,
```

v13.0 made *backend* CSP unconditional; the two frontend flags were never
promoted, in 13.x or 14.x.

We emit **no executable inline script**, which makes the question moot: endpoint
URL, record identity and request token travel in `data-*` attributes on the
custom element, and behaviour ships as a real ES module. That is compliant under
any policy allowing same-origin scripts, needs no nonce, and is identical code
on both versions — which also sidesteps the `useNonce` → `csp` option rename
(v14.2, Feature #100887, `useNonce` deprecation-logged on v14, absent as a name
on v13).

The cost avoided is concrete: a CSP nonce implies a
`Cache-Control: private, no-store` response header and forces the nonce in
cached HTML to be renewed — stated in the changelog for 13.4.x,
Important #107062. An inline `<script nonce>` would make every page carrying an
editable record uncacheable, on top of the `USER_INT` plugin.

If a site enables frontend CSP and our module ever needs a directive the default
policy lacks, the mechanism is `Configuration/ContentSecurityPolicies.php` in
the extension. A same-origin module and a same-origin `fetch()` should not need
one.

## v13 vs v14

Everything the transport rests on is symmetric. The differences are real but
sit beside the decision, not inside it.

| Concern                                                | v13.4                                | v14.3                                       |
|--------------------------------------------------------|--------------------------------------|---------------------------------------------|
| `typeNum`, `PAGE`, `config.disableAllHeaderCode`       | unchanged                            | unchanged                                   |
| `EXTBASEPLUGIN`, `USER_INT`                            | present                              | present                                     |
| `eid` vs `authentication` position                     | #5 vs #9                             | #5 vs #9                                    |
| `RequestToken` / `SecurityAspect` / `NoncePool`        | unchanged since 12.0                 | unchanged since 12.0                        |
| `RequestBuilder` merges body only for `POST`           | same                                 | same                                        |
| Parsed body from `$_POST` only                         | same                                 | same                                        |
| Frontend CSP feature flags                             | `false` / `false`                    | `false` / `false`                           |
| `TypoScriptFrontendController` / `frontend.controller` | present, deprecated (13.4 #105230)   | **removed** (14.0 #107831)                  |
| Response headers out of a plugin                       | raw `header()` calls mid-render      | collected in `frontend.response.data`       |
| `configurePlugin()` 5th argument                       | required, else deprecation (#105076) | unused but tolerated; non-`CType` throws    |
| `ExtensionUtility::PLUGIN_TYPE_PLUGIN`                 | present, deprecated                  | **removed** (14.0 #105377)                  |
| `#[AsAllowedCallable]` for TypoScript callables        | absent                               | required (14.0 #108054)                     |
| Extbase `#[Authorize]` / `#[RateLimit]`                | absent                               | present (14.2 #107826 / #108982)            |
| Asset nonce option key                                 | `useNonce`                           | `csp` (`useNonce` deprecated, 14.2 #100887) |

Consequences for this extension:

- **`configurePlugin()` needs no version split.** Pass
  `ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT` — the constant exists in both
  and the value `'CType'` is accepted by both. Omitting it is only safe on v14.
- **The response-header path changed but the behaviour did not.** v13 emitted
  headers with `header()` from inside content rendering; v14 collects them in
  `ResponseData` and `RequestHandler` applies them. Returning `jsonResponse()`
  lands the `Content-Type` on the response either way. Code that relied on
  `header()` having already fired mid-render would break on v14 — we have none.
- **`#[Authorize]` and `#[RateLimit]` are v14-only** and belong in `Core14/`
  behind a shared interface, with the v13 counterpart doing the same checks in
  PHP. No conditionals in shared classes.
- **Rate limiting is a named gap on v13.** There is no Extbase-level equivalent;
  13.4.x offers global rate limiter configuration (Important #103140), which is
  not the same thing.

## See also

- [Modern frontend editing](Index.md) — the other pages of this design.
- [Authorization](authorization.md) — who may edit which record, and why the
  request token does not answer that.
- [DTOs and validation](dto-and-validation.md) — why rules are data rather than
  attributes, and what fills the `errors` array.
- [Persistence and sorting](persistence-and-sorting.md) — what Extbase writes
  and what it refuses to.
- [Core version aware code](../architecture/core-version-aware-code.md) — why
  the v14-only attributes are a directory, not an `if`.
- [Dependency injection](../architecture/dependency-injection.md) — why
  `Context` is injected instead of fetched from `GeneralUtility`.
- [Developer documentation](../Index.md)
