# AJAX transport

The frontend editing endpoints are **a dedicated page `typeNum` that renders one
`EXTBASEPLUGIN` content object**, with `config.disableAllHeaderCode = 1`, and
Extbase actions that return `$this->jsonResponse(...)`. Not eID, and not a
bespoke PSR-15 middleware.

This page records why, what the decision costs, and the request/response
contract that follows from it.

> [!NOTE]
> **This is code now.** The page type is registered in `ext_localconf.php`, the
> seven endpoints live in `Classes/Controller/ProfileAjaxController.php`, and the
> envelope in `Classes/Http/JsonEnvelope.php`. Two statements this page made
> while it was design only turned out to be wrong when the code was written, and
> both are corrected below: the content object the `PAGE` calls, and
> `config.no_cache`. They are called out where they sit rather than in a note,
> because a reader arriving at the section is the reader who needs them.

Line references point into the installed dependency set below
`.Build/vendor/`, which is TYPO3 v14 unless a v13 file is named explicitly.
The v13 counterparts were read from the 13.4 artifact and are noted where they
differ.

## The decision

```typoscript
modernextbasefrontendedit_ajax = PAGE
modernextbasefrontendedit_ajax {
    typeNum = 1589
    config {
        disableAllHeaderCode = 1
        disableLanguageHeader = 1
        admPanel = 0
        debug = 0
        no_cache = 1
    }
    10 = EXTBASEPLUGIN
    10 {
        extensionName = ModernExtbaseFrontendEdit
        pluginName = Ajax
    }
}
plugin.tx_modernextbasefrontendedit.view.formatToPageTypeMapping.json = 1589
```

Four properties of this construction carry the decision:

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
  response (`:1157`).
- **`config.no_cache = 1` is the only instrument that acts early enough** to keep
  the endpoint out of the page cache. That is the second correction, and it has
  its own section: [caching](#caching).

### Correction: not `tt_content.modernextbasefrontendedit_ajax`

An earlier revision of this page put `10 =< tt_content.modernextbasefrontendedit_ajax`
into the `PAGE`, on the reasoning that `configurePlugin()` writes that object
anyway. It does — and using it wraps every JSON response in a content element
frame.

`ExtensionUtility::configurePlugin()` generates

```typoscript
tt_content.modernextbasefrontendedit_ajax =< lib.contentElement
tt_content.modernextbasefrontendedit_ajax {
    templateName = Generic
    20 = EXTBASEPLUGIN
    …
}
```

(`cms-extbase/Classes/Utility/ExtensionUtility.php:64-72`). `lib.contentElement`
is a `FLUIDTEMPLATE` of Fluid Styled Content
(`cms-fluid-styled-content/Configuration/TypoScript/Helper/ContentElement.typoscript:2-17`),
`Generic.fluid.html` declares `<f:layout name="Default" />`, and that layout
opens with

```html
<f:if condition="{data.frame_class} != none">
    <f:then>
        <div id="c{data.uid}" class="frame frame-{data.frame_class} frame-type-{data.CType} …">
```

(`cms-fluid-styled-content/Resources/Private/Layouts/Default.fluid.html:2-6`).
On a `PAGE` object there is no `tt_content` row, so `data.frame_class` is empty,
it is therefore not `none`, and the `then` branch runs: the JSON document comes
back inside `<div id="c" class="frame frame- frame-type- frame-layout-">`,
together with the anchor and the header/footer partial renders. `json_decode()`
on the client fails on the first character.

The `configurePlugin()` call is still made, and it is made for one thing only:
`registerControllerActions()` writes the controller/action allow-list into
`$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']`, which is what
`RequestBuilder` validates an incoming controller and action against and what
`Bootstrap::isExtbaseRequestCacheable()` reads for the `USER_INT` conversion.
The TypoScript it generates alongside is simply not referenced. The plugin also
gets no `Configuration/TCA/Overrides/tt_content.php` entry — it is an endpoint,
not something an editor places on a page, and `configurePlugin()` adds no TCA by
itself.

### Caching

**This is the correction that matters most on the page, because the failure mode
is a cached JSON response.** An earlier revision argued that the plugin-level
non-cacheable registration is the precise instrument and that `config.no_cache`
is a blunt page-wide toggle the `PAGE` object therefore does not need. The first
half is right about the *plugin*; the conclusion about the *page* is wrong, and
the ordering in `RequestHandler` is why.

Every action is registered in the `$nonCacheableControllerActions` argument of
`ExtensionUtility::configurePlugin()`. The bootstrap reads that list and converts
the object to `USER_INT` *before* the action runs (`Bootstrap.php:143-151`), and
returns `''` for the cached pass. So the plugin body executes in the **non-cached
pass** — and `RequestHandler::handleRequest()` runs that pass at
`cms-frontend/Classes/Http/RequestHandler.php:234-238`, **after** it has written
the page cache entry at `:174-226`:

```php
// cms-frontend/Classes/Http/RequestHandler.php:169-174, abridged
$event = new AfterCacheableContentIsGeneratedEvent($request, $content, …, $cacheInstruction->isCachingAllowed());
$event = $this->eventDispatcher->dispatch($event);
…
// Write page cache if allowed
if ($event->isCachingEnabled()) {

// :234
if ($pageParts->hasNotCachedContentElements()) {
    $content = $this->calculateNonCachedElements($request, $content);
```

A `disableCache()` call issued from inside the controller therefore **cannot
prevent that write**. It is executed at `:236`, two branches after the decision
it would have to influence. What gets cached is the page with the `USER_INT`
placeholder in it, which is not the JSON body — but it is a page cache entry for
an endpoint, keyed on frontend user *group* ids
(`PrepareTypoScriptFrontendRendering.php:322-344`), and it is exactly the entry
this design promises does not exist.

`config.no_cache = 1` is early enough. The middleware reads it out of the
TypoScript config tree and routes it into the **same** cache instruction request
attribute that Feature #102628 introduced in v13.0:

```php
// cms-frontend/Classes/Middleware/PrepareTypoScriptFrontendRendering.php:261-264
if ($setupConfigAst->getChildByName('no_cache')?->getValue()) {
    // Disable cache if config.no_cache is set!
    $cacheInstruction = $request->getAttribute('frontend.cache.instruction');
    $cacheInstruction->disableCache('EXT:frontend: Disabled cache due to TypoScript "config.no_cache = 1"');
}
```

That happens before `return $handler->handle($request);` at `:270`, i.e. before
page generation begins, so `isCachingAllowed()` is already `false` when
`RequestHandler` builds the event at `:169` and the whole block at `:174-226` is
skipped. **It is not a different mechanism from the PHP call — it is the same
attribute, set early enough to matter.** The objection that it is page-wide does
not apply here: the page *is* the endpoint.

The controller still calls it as well, and that is not redundant:

```php
// Classes/Controller/ProfileAjaxController.php, initializeAction()
$this->request->getAttribute('frontend.cache.instruction')
    ->disableCache('EXT:modern_extbase_frontend_edit: …');
```

`addHttpHeadersToResponse()` at `:244` calls `getClientCacheHeaders()` at
`:1189`, whose first conjunct is `$cacheInstruction->isCachingAllowed()`
(`:1218`), and whose fallback is an explicit `Cache-Control: private, no-store`
(`:1274-1279`). So the PHP call is what pins the *client* cache headers to a
value that does not depend on the TypoScript a site ships.

Two things about that call are worth stating exactly rather than approximately,
because both are easy to overclaim:

- It is **defence in depth, not the sole cause**. `!hasNotCachedContentElements()`
  at `:1219` is false too while every action is registered non-cacheable, so the
  `private, no-store` fallback is reached either way. The call earns its place by
  not depending on that registration staying as it is.
- It does **not** reach the failure responses. Those are thrown as
  `PropagateResponseException` and returned by the outermost
  `response-propagation` middleware (`ResponsePropagation.php:33-38`), which
  never passes through `addHttpHeadersToResponse()`. A `403` or a `422` from
  these endpoints carries the headers the controller set and no `Cache-Control`
  at all. That is acceptable — the status codes involved are not
  heuristically cacheable — but it is not what "on every response" would mean.

`TypoScriptFrontendController->no_cache` and `set_no_cache()` are not used for
any of this. Both were marked read-only/internal in 13.0 (Breaking #102621,
which names `frontend.cache.instruction` as the replacement) and the whole class
is gone in 14.0 (Breaking #107831).

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

Anything else is refused with `405` and an `Allow: POST` header, before the
payload is looked at. The media type is checked in the same place and for a
second, independent reason: a cross-origin `<form>` can only produce
`application/x-www-form-urlencoded`, `multipart/form-data` or `text/plain`, so
insisting on `application/json` costs a browser a preflight it will not send.
That is a cheap CSRF barrier and it does **not** replace the request token.

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

| Situation                                                          | Status | Reason                                                                             |
|--------------------------------------------------------------------|--------|------------------------------------------------------------------------------------|
| Read or write succeeded                                            | `200`  | the only sub-300 status a frontend plugin can produce                              |
| Malformed JSON, wrong type, unknown field, non-JSON `Content-Type` | `400`  | genuinely malformed request; matches Extbase's `errorAction()`                     |
| No or invalid request token, or a write without a login            | `403`  | a statement about the caller, never about a record                                 |
| Record absent, or not part of the caller's owned set               | `404`  | deliberately not distinguished from "forbidden"                                    |
| Any verb other than `POST`                                         | `405`  | sent with an `Allow: POST` header; see [POST for everything](#post-for-everything) |
| A write issued while a workspace is active                         | `409`  | authenticated and authorised — it is the session state that makes it unanswerable  |
| Well-formed request, domain validation failed                      | `422`  | the field-level case, distinguishable from `400` by the client                     |
| Rate limit exceeded                                                | `429`  | **not implemented.** v14 `#[RateLimit]` only; no equivalent on v13                 |

`405` and `409` were missing from this table while it was design only, and both
are real answers of the implementation. The `409` is deliberately not a `403`:
`403` here means "the caller may not do this", and a workspace refusal is not
about the caller's rights at all — the same caller in the live workspace is
allowed. `405` carries `Allow: POST` because a status code that names no
alternative is a status code a client cannot act on.

Neither is `#[Authorize]` involved in the `403`. The v14 attribute exists and is
**not used** — the checks are plain statements at the top of every write, run in
a fixed order and identical on both core versions, so there is no `Core13/` and
`Core14/` controller pair to keep in sync. See
[authorization](authorization.md#the-boundary-is-code-and-it-is-not-an-attribute).

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

## The seven endpoints

All seven are `POST` to the same `typeNum` URL with
`Content-Type: application/json`, distinguished by the Extbase action in the
query string — `tx_modernextbasefrontendedit_ajax[controller]=ProfileAjax` and
`…[action]=save`, both part of the cHash. Every write carries the
`X-TYPO3-RequestToken` header.

`child` is the discriminator that decides whether a payload addresses the
profile itself or one of its two collections. It is a closed set — `address` or
`email` — and a name outside it is a `400`, not a `404`: the client either knows
the API or it does not, and answering "no such record" would be an odd way to say
"unknown parameter".

| Action               | Payload                                        | What it does                                                              |
|----------------------|------------------------------------------------|---------------------------------------------------------------------------|
| `read`               | `uid?`                                         | The caller's profile with both collections. No token, no login check.     |
| `save`               | `uid`, `data`, `child?`, `childUid?`           | Every writable property of one record at once — the profile or one child. |
| `saveField`          | `uid`, `field`, `value`, `child?`, `childUid?` | One named field of one record, for the inline editor.                     |
| `addChild`           | `uid`, `child`, `data`                         | Appends one child to a collection; it sorts last.                         |
| `removeChild`        | `uid`, `child`, `childUid`                     | Removes one child *and deletes the row* — a detach alone would orphan it. |
| `reorderChildren`    | `uid`, `child`, `order`                        | Puts a collection into the submitted order.                               |
| `setChildVisibility` | `uid`, `child`, `childUid`, `hidden`           | Sets the `hidden` flag of one child to an explicit boolean.               |

Four properties of that surface are decisions rather than accidents:

- **`uid` filters, it never looks up.** It is matched against the set the
  *session* owns, and a uid that is not in it answers exactly like a uid that
  does not exist. On `read` it is optional; without it the caller gets the owned
  profile with the lowest uid. → [authorization](authorization.md)
- **`order` has to be a permutation of the whole collection.** That is a security
  property, not API pedantry: the submitted list replaces the collection
  wholesale, so a short list would drop every record it omits — and those records
  are then deleted as orphans. A wrong length or a duplicate uid is refused
  before anything is touched.
- **`setChildVisibility` takes an explicit boolean, not a toggle.** An idempotent
  endpoint is what a client with an optimistic UI needs; a real toggle answers
  differently depending on a state the client may have wrong.
- **Every endpoint answers with the whole aggregate**, including the ones that
  changed a single field, so a client that patched its own state cannot drift and
  a client that moved a child gets the resulting order back with it.

**Image upload is deliberately not one of these seven.** It is a different
transport — `multipart/form-data`, not a JSON body — a different failure surface
and a different cleanup rule for the file behind a replaced reference. Bolting it
onto an endpoint set whose entire contract is "JSON in, JSON out" would make
every rule on this page conditional. It is a change of its own, and until it
lands the profile image is a backend-only field.
→ [Image handling](image-handling.md)

### The wire format

**Full save** — every writable property of the profile:

```json
{
  "uid": 42,
  "data": {
    "shortname": "ada",
    "firstname": "Ada",
    "lastname": "Lovelace",
    "birthday": "1815-12-10",
    "bio": ""
  }
}
```

**Partial save** — one field, for the inline editor. `field` must be a name the
DTO's rule set knows; the rule set is the single whitelist for both "what is
validated" and "what may be addressed partially", and an unknown name is refused
twice — once by the validator, once by the mapper's closed `switch`:

```json
{
  "uid": 42,
  "field": "firstname",
  "value": "Ada"
}
```

**A child save** adds the discriminator and the child uid, and `data` is then
that child's own DTO — so nothing about the parent can be written through it:

```json
{
  "uid": 42,
  "child": "address",
  "childUid": 7,
  "data": {
    "type": "home",
    "line1": "1 Marylebone Road",
    "line2": ""
  }
}
```

**A reorder** carries the full permutation:

```json
{ "uid": 42, "child": "email", "order": [9, 7, 8] }
```

**Success**, `200` — the same document from all seven:

```json
{
  "data": {
    "uid": 42,
    "shortname": "ada",
    "firstname": "Ada",
    "lastname": "Lovelace",
    "birthday": "1815-12-10",
    "bio": "",
    "hidden": false,
    "addresses": [
      { "uid": 7, "type": "home", "line1": "1 Marylebone Road", "line2": "", "hidden": false }
    ],
    "emails": [
      { "uid": 9, "type": "private", "email": "ada@example.org", "hidden": false }
    ]
  }
}
```

`data` is the persisted state as the server sees it after the write, not an echo
of the request — a client that trusts its own optimistic update will drift.
`birthday` is `""` for "no birthday", matching the DTO default, and its format is
pinned by `ProfileData::BIRTHDAY_FORMAT` so that what is read back is spelled
exactly like what may be written.

> [!NOTE]
> **The profile's own `hidden` flag is readable and not writable.** It is in the
> response so an editor can show the state, and no endpoint can change it —
> `setChildVisibility` is, as its name says, for children. Publishing a profile
> from the frontend needs its own action with its own rule about who may make a
> record public, and that rule is not written yet. Shipping the column as
> writable "for symmetry" would ship the missing rule with it.

> [!NOTE]
> **Concurrent editing is not designed.** Two sessions editing the same record
> overwrite each other, last write wins, and neither is told. Adding optimistic
> locking later means a record version in the response and a conflict status on
> the write — deliberately left out here rather than reserved half-way, because
> a field nobody validates is worse than an absent one.

**Validation failure**, `422` — one entry per rejected property, `field` being
`null` for an error attached to the object rather than to a property:

```json
{
  "errors": [
    { "field": "shortname", "code": 1712345678, "message": "Must not be empty." },
    { "field": "birthday",  "code": 1712345679, "message": "Must be a valid date." }
  ]
}
```

**Everything else** — `400`, `403`, `404`, `405`, `409` — the same envelope with
one entry and no field context:

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
$jwt = RequestToken::create(ProfileAjaxController::REQUEST_TOKEN_SCOPE)
    ->withMergedParams(['request' => ['uri' => $endpointUri]])
    ->toHashSignedJwt($signingSecret);
```

The scope is a `public` constant on the controller rather than a literal on both
sides, because a scope that drifts apart rejects every write, and the failure
looks like a broken token rather than like a typo. It is an opaque identifier and
grants nothing — it only keeps a token issued for something else from being
replayed here.

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
- **`#[Authorize]` is v14-only and is not used.** The design allowed for a
  `Core13/`/`Core14/` controller pair, the v14 half carrying the attribute. The
  implementation does the checks in PHP instead, in one controller shared by both
  versions — the attribute would have bought a declaration and cost a duplicated
  controller, and the checks it would have replaced are four statements. There is
  therefore no version split in the transport at all.
  → [authorization](authorization.md#the-boundary-is-code-and-it-is-not-an-attribute)
- **Rate limiting is not implemented, on either version.** `#[RateLimit]` is
  v14-only, and 13.4.x offers global rate limiter configuration (Important
  #103140), which is not the same thing. Adding it on v14 alone would make the
  two versions differ in their security posture, which is worse than the honest
  gap.

## See also

- [Modern frontend editing](Index.md) — the other pages of this design.
- [Plugins and the Fluid layer](plugins-and-fluid.md) — the same
  `configurePlugin()` rule applied to the two read plugins.
- [Authorization](authorization.md) — who may edit which record, and why the
  request token does not answer that.
- [DTOs and validation](dto-and-validation.md) — why rules are data rather than
  attributes, and what fills the `errors` array.
- [Persistence and sorting](persistence-and-sorting.md) — what Extbase writes
  and what it refuses to, and what the seven endpoints hand to the write path.
- [Image handling](image-handling.md) — the upload that is deliberately not one
  of the seven endpoints.
- [Core version aware code](../architecture/core-version-aware-code.md) — why
  the v14-only attributes are a directory, not an `if`.
- [Dependency injection](../architecture/dependency-injection.md) — why
  `Context` is injected instead of fetched from `GeneralUtility`.
- [Developer documentation](../Index.md)
