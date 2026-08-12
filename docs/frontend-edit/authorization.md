# Authorization

Who the caller is, which records they may touch, and where each defence lives.
The transport is settled elsewhere — a dedicated page type running an
`EXTBASEPLUGIN`, never cached, `POST` only. This page settles the rules that
apply on top of it.

Code paths quoted below are relative to `.Build/vendor/typo3/`, so they can be
opened in the installed dependency set.

**The boundary is enforced in code now.** `Classes/Security/` holds
`ProfileOwnershipResolverInterface` and its column-based implementation
`FrontendUserProfileOwnershipResolver`; `Classes/Controller/ProfileAjaxController.php`
is the write side and applies every rule below; `Classes/Domain/Persistence/`
holds the workspace guard that the controller and the persistence service both
assert. What is *not* there is the shape this page originally planned for it —
a separate rule service with a v13 and a v14 adapter — and the section on it
explains what replaced it and why.

> [!IMPORTANT]
> The read plugins already ask the resolver a question, and its answer decides
> whether an edit link is rendered. That is a **display** decision. It is not
> one of the defences on this page, it protects nothing, and no defence here may
> be replaced by it.
> → [Plugins and the Fluid layer](plugins-and-fluid.md)

## Identity comes from the `Context` aspect

`Context::getAspect('frontend.user')` is the only supported source of frontend
user identity on both target versions.

- `$GLOBALS['TSFE']->fe_user` is **not deprecated, it is gone** — removed in
  v13.0 and a PHP fatal error on both v13.4 and v14.3
  (Breaking #102605, `cms-core/Documentation/Changelog/13.0/Breaking-102605-TSFE-fe_userRemoved.rst`).
  The same entry ranks the two replacements and calls the `Context` aspect the
  preferred one, because the request attribute exposes `@internal` details.
- The aspect is **never `null`**. If it was not set, `Context` lazily creates an
  empty one (`cms-core/Classes/Context/Context.php:97-99`), which is
  indistinguishable from a genuine anonymous request. Every check therefore
  starts from `isLoggedIn()` (`cms-core/Classes/Context/UserAspect.php:79-82`),
  not from the presence of the aspect.
- Outside Extbase — a middleware, an event listener — use
  `$request->getAttribute('frontend.user')`, set in the same statement pair as
  the aspect (`cms-frontend/Classes/Middleware/FrontendUserAuthenticator.php:57-70`).
  It is `null` outside the frontend stack, so it is always guarded with
  `instanceof`, exactly as core does
  (`cms-core/Classes/Messaging/FlashMessageQueue.php:217-219`).

Two consequences that are easy to get wrong:

**The aspect is resolved per call, never cached in a property.** `Context` is a
singleton, and its `frontend.user` aspect is *replaced* — by the authentication
middleware (`FrontendUserAuthenticator.php:69`) and again by `PreviewSimulator`
(`cms-frontend/Classes/Middleware/PreviewSimulator.php:180`). A `final readonly`
service may hold `Context`; it must not hold the aspect.

**Group ids are not a login check.** `getGroupIds()` returns `[0, -1]` for an
anonymous visitor and `[0, -2]` plus the real groups for a logged-in one
(`cms-core/Classes/Context/UserAspect.php:103-125`). Group `0` is always
present, so a membership test against it is meaningless — see the `#[Authorize]`
footgun below, where this turns into an open door.

## Ownership is a resolver, not a column

`TYPO3\CMS\Extbase\Domain\Model\FrontendUser` was deprecated in v11.4 and
**removed in v12.0**
(`Changelog/11.4/Deprecation-94654-GenericExtbaseDomainClasses.rst`,
`Changelog/12.0/Breaking-96107-DeprecatedFunctionalityRemoved.rst:28-29`), so it
exists on neither target version. The choice is between a plain `int` and an own
model mapped onto `fe_users`.

This extension carries a plain `int` property plus a TCA `select` on `fe_users`.
With `renderType => 'selectSingle'` and `maxitems => 1` that is mapped as a
**scalar column, not a relation**: `ColumnMapFactory` decides the relation type
from the PHP property type, and `strpbrk('int', '_\\')` is `false`
(`cms-extbase/Classes/Persistence/Generic/Mapper/ColumnMapFactory.php:136-149`),
while the `HAS_MANY` branch excludes single-value selects
(`:181-190`). The ownership comparison is then `int === int`, unit testable with
no database, and no property mapper path leads into a table that holds
`password`, `felogin_forgotHash` and `TSconfig`.

**That column is an implementation detail, and the rule must not know about
it.** The migration target for this design, `academic-persons`, resolves
ownership through an **n:m MM relation** —
`tx_academicpersons_domain_model_profile.frontend_users` ↔
`fe_users.tx_academicpersons_profiles` over `tx_academicpersons_feuser_mm` — so
one frontend user owns *several* profiles and its `listAction` is built around
that. A hardcoded `profile.fe_user` comparison cannot be adopted there.

The ownership question is therefore asked through an interface:

| Layer                  | Responsibility                                                        | Where it ended up                                                  |
|------------------------|-----------------------------------------------------------------------|--------------------------------------------------------------------|
| Ownership resolver     | Given a frontend user id, which records are owned — and nothing else. | `Classes/Security/`, as designed.                                  |
| Ownership rule service | Deny before compare, anonymous is never an owner, throw or return.    | Folded into the resolver and the controller — see below.           |
| Version adapter        | How the rule is invoked: `#[Authorize]` on v14, a guard call on v13.  | **Not built.** One controller, valid on both versions — see below. |

Only the resolver knows the storage shape. This extension ships a column-based
implementation; an MM-based one is a second class implementing the same
interface, with no change to the rule, the controllers or the tests that cover
the rule. The interface therefore speaks in **owned sets**, not in "the owner of
this record": `int $frontendUserId → the records they own` maps onto both a
column and an MM table, whereas `record → owner uid` silently assumes 1:1.

## The boundary is code, and it is not an attribute

This page planned a separate rule service with two version adapters: a `bool`
variant for a v14 `#[Authorize(callback: …)]`, an `assert*` variant for a v13
controller, and a `Core13/`/`Core14/` controller pair over a shared abstract
base. **None of that was built, and the reason is worth recording rather than
quietly dropping.**

What exists instead is four statements, run in a fixed order at the top of every
write, in **one** controller that is valid on both core versions:

```php
// Classes/Controller/ProfileAjaxController.php, beginWrite()
$this->assertPostRequest();   // 405, with Allow: POST
$this->assertRequestToken();  // 403 — proof of visit
$this->assertAuthenticated(); // 403 — a logged-in frontend user
$this->assertLiveWorkspace(); // 409 — not a rights question
```

followed by `resolveOwnedProfile()`, which is the only entry into persistence and
takes its argument from the session. The order is the point: each of the four
refuses **before a single request value is looked at**, so a rejected caller
learns nothing about the payload it sent.

`#[Authorize]` would have replaced `assertAuthenticated()` and moved the
ownership question into a callback. Weighed against the repository's own rule
that version differences split classes, that trade is bad: the attribute is
v14-only (Feature #107826, attribute at
`cms-extbase/Classes/Attribute/Authorize.php:20-34`; v13.4 has neither it nor
`ActionAuthorizationService`), so taking it means a duplicated controller in
`Core13/` and `Core14/` and an abstract base holding everything else — to save
one statement, and to make the two versions run different code on the security
path. **A boundary that is identical on both versions is worth more than a
declarative spelling of it on one.**

Three things about the attribute stay true and are the reason to re-read this
section before anyone reaches for it again:

1. **`#[Authorize]` runs after property mapping and validation**, inside
   `callActionMethod()` (`cms-extbase/Classes/Mvc/Controller/ActionController.php:465-479`),
   while `initializeAction()` and `initialize<Foo>Action()` run *before* it
   (`:369-374`). **No state change may happen in an `initialize*Action()`** — it
   executes for a caller who is about to be rejected with 403. The one thing this
   extension does there is disable the frontend cache, which changes no state and
   has to cover the rejected callers too.
2. The callback class must be **public in the container**
   (`#[Autoconfigure(public: true)]`), an explicit exception to "services are
   private" forced by the framework.
3. Denial produces a 403 through `PropagateResponseException`
   (`ActionController.php:998-1007`) — which is the same mechanism the controller
   uses by hand, for every status, so that a client that always parses JSON
   always gets JSON.

`FrontendUserProfileOwnershipResolver` is `final readonly`, holds a repository
and nothing request-derived, takes the frontend user id as an argument on every
call, and **denies before it compares**: a non-positive id resolves to the empty
set, so a record whose owner column is `0` — written by an editor or by a bug —
is owned by nobody rather than by every anonymous visitor. That rule sits in the
resolver rather than one layer up precisely so it holds for a caller that skips
whatever is above it.

### `readAction` requires no token and makes no login check

This looks like a hole and is the opposite of one, so it is stated here rather
than left to be discovered and "fixed".

A read changes nothing, so a request token — which is proof of visit, not
authorisation — protects nothing on it. And refusing an anonymous caller with
`403` would make the endpoint answer **differently** for "not logged in" than for
"logged in, but not the owner". That difference is the enumeration oracle this
page exists to close: the second answer confirms that a profile exists and
belongs to somebody else.

Both cases instead end in the one uniform `404`, with the identical body, because
an anonymous caller owns nothing and `resolveOwnedProfile()` therefore finds
nothing to return. The two request shapes are indistinguishable on the wire.

The writes are the other way round: `assertAuthenticated()` answers `403`, and
that is *not* an oracle, because it answers a question about the caller and not
about a record. A caller who is not logged in learns that they are not logged in.

### The `requireGroups` footgun

```php
// cms-extbase/Classes/Service/ActionAuthorizationService.php:81-100
if (empty($authorize->requireGroups)) {
    return true;
}
$userGroupIds = $userAspect->getGroupIds();
```

`requireGroups` **does not imply `requireLogin`**, and `getGroupIds()` returns
`[0, -1]` for an anonymous visitor. `#[Authorize(requireGroups: [0])]` therefore
authorises *everyone*, including callers with no session at all. Any group
requirement is paired with `requireLogin: true`, always.

The changelog's own example compares `$myObject->getOwner()->getUid()` with
`$userAspect->get('id')` and has **no login check** — and `get('id')` is `0`
when anonymous. Copy the idea, not the code.

## Child ownership without a trusted-uid problem

The naive fix — look the child up by its uid, then check the parent's owner —
re-introduces the problem one level down. It starts from an attacker-supplied
uid and trusts `$child->getParent()` to be the real parent; it happens to work,
and it breaks the moment a second parent path exists. Extbase's property mapper
will fetch any uid it is given and has no notion of ownership
(`cms-extbase/Classes/Property/TypeConverter/PersistentObjectConverter.php:146`),
so "trust the property mapper" is not available either.

**The child is never looked up. It is reached by navigating the owned
aggregate, and the client uid only filters the set the server already built.**

Concretely, and in this order:

1. Resolve the frontend user id from the session — `Context`, never a request
   parameter.
2. Ask the ownership resolver for the owned aggregate(s). This is the **only**
   entry point into persistence for a mutating request, and its argument comes
   from the session.
3. Select the child by the client-supplied uid **and** the resolved parent uid
   together, through `findByUidAndProfileUidIncludingHidden()`. The client
   supplies one half of a `logicalAnd()`; the session supplies the other.
4. A uid that is not in the set produces the same response as a uid that does
   not exist. That indistinguishability is the point.

Step 3 is where the implementation differs from what this page first described,
and the difference is worth keeping. The original recipe was to iterate the
parent's `ObjectStorage` and compare `getUid()`. That cannot work here: relations
are reconstituted with query settings built from scratch
(`DataMapper::getPreparedQuery()`), so the parent's collection **never contains
the children the owner has hidden** — the very records this feature exists to
show and to toggle. An owner constrained finder is the same guarantee expressed
as a query constraint rather than as an in-memory filter, and it sees the hidden
rows. What has not changed is the property that matters: a client uid is never
the *whole* of a lookup.

This is stated as a rule a patch can be reviewed against:

| Reviewable statement                                                                   | How it is checked                                                              |
|----------------------------------------------------------------------------------------|--------------------------------------------------------------------------------|
| No `->findByUid(` anywhere in the AJAX controller.                                     | grep, and a gate-sized assertion in review.                                    |
| No `__identity` mapping is configured for any child argument.                          | grep for `__identity` and `CONFIGURATION_MODIFICATION_ALLOWED`.                |
| Every repository entry point takes either a frontend user id or a resolved parent uid. | Read the repository's public method signatures.                                |
| A client uid never appears alone in a constraint — always beside a resolved uid.       | Follow every request-derived variable to its use.                              |
| Reads keep `setRespectStoragePage(true)`.                                              | `Typo3QuerySettings.php:55` is the default — the review looks for an override. |

Adding a fourth child type cannot introduce a new hole, because it introduces no
new lookup. That is the whole reason for choosing a resolution strategy over a
check.

New children derive their `pid` from the **parent record**, not from
configuration and never from the request. Neither upstream extension ships
`persistence.storagePid`, so a storage-page based scheme would not survive
adoption.

## The checklist

Each row is a risk, the concrete defence, and the place the defence lives. A
defence that lives in the controller only is a defence that the next controller
forgets.

| Risk                                                  | Defence                                                                                                                                    | Where it lives                                                                       |
|-------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------|
| IDOR on child records (address, e-mail, image)        | Navigate the owned aggregate; the client uid filters, it never looks up. No `findByUid()` in the controller.                               | `findByUidAndProfileUidIncludingHidden()` + `ProfileAjaxController`, per child type. |
| Mass assignment (no `__trustedProperties` in JSON)    | A `final readonly` DTO with exactly the writable fields. Never `allowAllProperties()`.                                                     | `Classes/Dto/`; the mappers apply a DTO onto the resolved entity.                    |
| `pid` escape (`profile[pid]=1`)                       | `pid` is not a DTO property and never appears in an allow-list; new records inherit the parent's `pid`.                                    | DTO shape; `ChildCollectionSynchronizer` assigns it from the parent record.          |
| Profile enumeration via the read endpoint             | `read` takes only an optional uid that **filters the owned set**; uniform 404, never 403, and no login check.                              | `resolveOwnedProfile()` and the single `notFound()` helper.                          |
| Hidden-record toggling (`profile[hidden]=0`)          | `hidden`, `starttime`, `endtime`, `deleted` are not DTO properties; the only path to `hidden` is its own action.                           | DTO shape; `setChildVisibility`, which is children-only.                             |
| Enable-field bypass leaking editor-disabled records   | `setEnableFieldsToBeIgnored(['disabled'])` on the **owner-constrained** query only, never repository-wide.                                 | `AbstractEditRepository::createEditQuery()`; never `initializeObject()`.             |
| Global visibility bypass                              | `VisibilityAspect` is never touched — it is request-global and would un-hide other people's records too.                                   | Nowhere. This is a "do not".                                                         |
| Cross-user leak through the group-keyed page cache    | The endpoint page type is never cached; the edit plugin markup is `USER_INT`.                                                              | `config.no_cache = 1` on the `PAGE`, plus the non-cacheable action registration.     |
| Silent live-record write while a workspace is active  | Refuse the write: `Context` `workspace.isLive` is asserted before any persistence call.                                                    | `WorkspaceGuard`, asserted by the controller **and** by the persistence service.     |
| Hostile file upload (stored XSS, resource exhaustion) | An allow-list of four raster MIME types — no SVG — plus a size and a dimension bound, all validated before anything is moved into storage. | `Validation\ProfileImageUploadRules`, applied by `initializeUploadImageAction()`.    |
| Destroying another record's file reference            | Delete a replaced `sys_file` only when nothing but our own reference row points at it, counted after the flush.                            | `UnreferencedFileCleanupService`, called only by `ProfilePersistenceService`.        |

Three rows changed shape when the code was written, and the table above is the
corrected version:

- **Profile enumeration.** This page originally required the read endpoint to
  take *no identifier at all*. It takes an optional `uid`, because the resolver
  interface allows a frontend user to own several profiles and the endpoint has
  to be able to say which one. The oracle stays closed by a different mechanism:
  the uid is matched against the owned set and never seeds a query, and a uid
  that is not in it produces the same status and the same body as a uid that does
  not exist. → [`readAction` requires no token](#readaction-requires-no-token-and-makes-no-login-check)
- **Hidden-record toggling.** "Publishing is an explicit action" is true for
  children and **not** implemented for the profile itself: `setChildVisibility`
  addresses a child, and the profile's own `hidden` flag is readable in every
  response and writable by nothing.
  → [Persistence and sorting](persistence-and-sorting.md#the-profiles-own-hidden-flag-is-not-writable)
- **The page cache.** `config.no_cache = 1` is named here, rather than the
  plugin-level registration alone, because the registration converts the plugin
  to `USER_INT` — which runs *after* the page cache is written.
  → [AJAX transport](ajax-transport.md#caching)

### Two rows that are more subtle than they look

**Mass assignment.** Extbase does *not* auto-persist a merely mapped object —
only objects handed to `add()`/`update()`/`remove()` are written
(`cms-extbase/Classes/Persistence/Generic/PersistenceManager.php:117-121`). But
once `update()` is called, `persistObject()` writes **every** persistable
property (`cms-extbase/Classes/Persistence/Generic/Backend.php:256-275`), so a
`feUser`, `pid` or `hidden` mutated by the property mapper is flushed with it.
What normally gates this is the HMAC-signed `__trustedProperties` token, from
which `MvcPropertyMappingConfigurationService` builds the allow-list
(`:118-147`, `:161-176`) — and `shouldMap()` otherwise defaults to deny
(`cms-extbase/Classes/Property/PropertyMappingConfiguration.php:98-112`).

**A JSON request carries no such token.** The service returns immediately, no
property is allowed, and the tempting fix is `allowAllProperties()` in
`initializeUpdateAction()`. That is precisely the mass assignment hole: it
re-enables the property mapper for the whole entity while removing the signed
allow-list that made it safe. The DTO avoids the question — there is no mapper
path to a property the DTO does not have. (`allowAllPropertiesExcept()` is no
better: a deny-list forgets the property added next sprint.)

**The `pid` escape.** `setPid()` is public API on every Extbase entity
(`cms-extbase/Classes/DomainObject/AbstractDomainObject.php:35,102,110`), `pid`
has no leading underscore and is therefore both mappable and persistable
(`cms-extbase/Classes/Reflection/ClassSchema.php:401-407`) — and the persistence
layer **prefers the object's own `pid` over all configuration**:

```php
// cms-extbase/Classes/Persistence/Generic/Backend.php:855-882
if (ObjectAccess::isPropertyGettable($object, AbstractDomainObject::PROPERTY_PID)) {
    $pid = ObjectAccess::getProperty($object, AbstractDomainObject::PROPERTY_PID);
    if (isset($pid)) {
        return (int)$pid;
    }
}
```

`newRecordStoragePid` and `persistence.storagePid` are consulted only *after*
that, so no amount of configuration protects a record whose `pid` the request
was allowed to set. Hence the rule: `pid` is never a DTO property.

**Enumeration.** 403 on a read is a positive existence oracle — it confirms the
record exists and belongs to somebody else. The read endpoint therefore never
answers `403`: it makes no login check at all, so an anonymous read and a
non-owner read take the identical path and end in the identical `404`. Where a
selector is unavoidable — the optional profile uid, and every child uid — "not
in my set" and "does not exist" return the same status *and the same body*,
produced by one `notFound()` helper so no action can deviate.

## The group-keyed page cache

The page cache identifier varies by **group ids, not by user uid**:

```php
// cms-frontend/Classes/Middleware/PrepareTypoScriptFrontendRendering.php:322-344
'groupIds' => implode(',', $this->context->getAspect('frontend.user')->getGroupIds()),
…
return $pageId . '_' . hash('xxh3', serialize($pageCacheIdentifierParameters));
```

For an edit plugin this is a leak shape of its own, not a theoretical one. Two
different logged-in users in the same frontend user group share **one** cache
entry, so a cached page carrying user A's profile is served to every other
member of A's groups. Being logged in does not disable the cache: the only
triggers are `&no_cache=1`, cHash failures, a backend user forcing a reload,
the preview aspect and `config.no_cache`. `CacheLifetimeCalculator` has no user
dimension at all
(`cms-frontend/Classes/Cache/CacheLifetimeCalculator.php:104-134`).

**The chosen transport removes the shape rather than defending against it**, and
it takes two instruments rather than one:

- `config.no_cache = 1` on the endpoint `PAGE`, which routes into the
  `frontend.cache.instruction` attribute (Feature #102628) from a middleware,
  i.e. **before** page generation, so no cache entry is written for the endpoint
  at all.
- Every action registered as non-cacheable, which converts the plugin to
  `USER_INT` (`cms-extbase/Classes/Core/Bootstrap.php:143-151`) so the edit
  markup on the surrounding page is not part of a cached page either.

The second does **not** imply the first: a `USER_INT` body runs after the page
cache has already been written, so a `disableCache()` issued from the action is
too late for it. That correction and its citations are on the transport page —
→ [AJAX transport](ajax-transport.md#caching).

The v14 authorization changelog makes the same point from the other side: a
custom authorization response must only be used for uncached actions, or it is
cached and served regardless of the caller's status.

Without the test named below, a regression here is invisible in development,
because a single developer is always their own cache entry.

## Workspace guard

Extbase writes are **workspace-blind**. The storage backend issues plain DBAL
statements against the live row — no `t3ver_wsid`, no `t3ver_oid`, no
`DataHandler`:

```php
// cms-extbase/Classes/Persistence/Generic/Storage/Typo3DbBackend.php:84-103,114-133
$connection->insert($tableName, $fieldValues, …);
$connection->update($tableName, $fieldValues, ['uid' => $uid], …);
```

So a write issued while a workspace is active silently modifies the **live**
record while the editor believes they are working in a draft. That is why the
guard is load-bearing and not defensive politeness: without it the feature has a
data-integrity bug that no test of the happy path can see, and the failure is
invisible until the live site changes.

The signal is `Context::getPropertyFromAspect('workspace', 'isLive', true)`;
`WorkspaceAspect` is byte-identical on v13.4 and v14.3, so this is shared code.
Core uses the same signal in the same area (`Typo3DbQueryParser.php:731`,
`PageRepository::getDefaultConstraints()`). A refused write throws
`WorkspaceWritesNotSupportedException` from `WorkspaceGuard`.

It is asserted in **two** places, and that is not a duplicated rule: the
controller asks `areWritesAllowed()` in `beginWrite()` so a refusal becomes a
clean `409` instead of an exception page, and `ProfilePersistenceService` calls
`assertWritesAllowed()` at the entry of every public write method so the rule
cannot be bypassed by adding a second caller. One condition, in one class, read
from one injected `Context` — two call sites, no second copy that can drift.

The `true` default of `getPropertyFromAspect()` does **not** mean "an absent
aspect reads as live"; an absent `workspace` aspect is impossible. What it
covers is narrower, and it is worked out with citations in
[Persistence and sorting](persistence-and-sorting.md#correction-what-the-true-default-actually-covers).

## How the rules are tested

The resolver is covered by `Tests/Functional/Security/`: the owned set includes
the owner's *hidden* profiles, never contains another user's profile, is empty
for an anonymous caller, and an ownerless profile belongs to nobody. The table
below is the acceptance list for the endpoints, and every row of it is a
functional test against a real frontend request rather than a controller called
in isolation — an authorization rule that is only exercised with the middleware
stack removed is not exercised.

Frontend user authentication is faithfully exercised by the testing framework: a
real `fe_sessions` row and a real JWT cookie are created, and core's own
authentication middleware then runs unmodified, resolving the cookie,
re-fetching the `fe_users` row with enable-field restrictions and resolving
groups including subgroups. The uid, the group list and expiry are therefore
genuinely covered.
The login *act* is not — no password hashing, no `felogin`, no MFA — so no test
may claim to cover the login flow.

The three request shapes are: no `InternalRequestContext` at all (anonymous),
`withFrontendUserId(2)` (logged in, not the owner) and `withFrontendUserId(1)`
(owner). There is no `withFrontendUserGroups()`; groups come from the
`fe_users.usergroup` column in the fixture.

| Checklist row          | What the functional test asserts                                                                                                       |
|------------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| IDOR on child records  | Per child type: a uid belonging to another profile returns 404, and the record is unchanged in the database afterwards.                |
| Mass assignment        | A payload carrying `feUser` (and one unknown field) leaves the stored owner untouched and does not error.                              |
| `pid` escape           | A payload carrying `pid` does not change the stored `pid` of the created or updated record.                                            |
| Profile enumeration    | Anonymous and non-owner reads return the identical status **and body**; an unowned uid answers exactly like an unknown one.            |
| Hidden-record toggling | A `hidden` key in a save payload does not change the stored flag; `setChildVisibility` does, for an owned child only.                  |
| Enable-field bypass    | An editor-disabled record of *another* user is absent from every response of the owner-constrained endpoint.                           |
| Group-keyed cache leak | Two users **in the same group** request the endpoint in one test; the bodies differ. This is the test that catches a re-enabled cache. |
| Workspace guard        | A request with `withWorkspaceId()` set is refused with `409`, and the live row is byte-identical afterwards.                           |
| Verb and media type    | A `GET`, and a `POST` without `application/json`, are refused before the payload is read — `405` with `Allow: POST`, and `400`.        |

The media type row does not hold for the image upload, and that is worth saying
here rather than only where it is decided. `uploadImage` insists on
`multipart/form-data`, which a cross origin `<form>` **can** produce, so the
cheap second CSRF barrier the JSON endpoints get for free does not exist there.
The request token is the only one. It is enough — a hash signed JWT bound to a
nonce cookie this browser holds, which an attacker cannot read — but it is the
only one, and that is the reason the token check on that endpoint runs before
anything else and must never be relaxed "because the upload is harmless".
→ [Image handling](image-handling.md#the-two-endpoints)

Per repository policy every one of these is **shown to fail** with its guard
removed before it is committed — an authorization test that passes for the wrong
reason is worse than no test, because it is trusted.

## See also

- [Plugins and the Fluid layer](plugins-and-fluid.md) — the ownership flag the
  read plugins render, and why it is not one of the defences on this page.
- [AJAX transport](ajax-transport.md) — the nine endpoints these rules guard,
  the status codes and the caching correction.
- [Persistence and sorting](persistence-and-sorting.md) — the write path behind
  the boundary, and what it does not do.
- [Class design](../architecture/class-design.md)
- [Dependency injection](../architecture/dependency-injection.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [Site based tests](../testing/site-based-tests.md)
- [Functional tests](../testing/functional-tests.md)
