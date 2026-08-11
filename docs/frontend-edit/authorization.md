# Authorization

Who the caller is, which records they may touch, and where each defence lives.
The transport is settled elsewhere — a dedicated page type running an
`EXTBASEPLUGIN`, never cached, `POST` only. This page settles the rules that
apply on top of it.

Code paths quoted below are relative to `.Build/vendor/typo3/`, so they can be
opened in the installed dependency set.

**Half of this page is code now.** `Classes/Security/` holds
`ProfileOwnershipResolverInterface` and its column-based implementation
`FrontendUserProfileOwnershipResolver`, and `Classes/Controller/ProfileController.php`
reads the frontend user through the `Context` aspect exactly as described below.
The rest — the rule service, the `Core13/`/`Core14/` adapters, the DTOs and every
write endpoint — **follows in a later change**, and the sections describing them
say so.

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

| Layer                  | Responsibility                                                        | State                          |
|------------------------|-----------------------------------------------------------------------|--------------------------------|
| Ownership resolver     | Given a frontend user id, which records are owned — and nothing else. | `Classes/Security/`, in place. |
| Ownership rule service | Deny before compare, anonymous is never an owner, throw or return.    | Later change, with the writes. |
| Version adapter        | How the rule is invoked: `#[Authorize]` on v14, a guard call on v13.  | Later change, with the writes. |

Only the resolver knows the storage shape. This extension ships a column-based
implementation; an MM-based one is a second class implementing the same
interface, with no change to the rule, the controllers or the tests that cover
the rule. The interface therefore speaks in **owned sets**, not in "the owner of
this record": `int $frontendUserId → the records they own` maps onto both a
column and an MM table, whereas `record → owner uid` silently assumes 1:1.

## One stateless rule service, two adapters

**This section describes code that does not exist yet.** What exists is the
resolver below it: `FrontendUserProfileOwnershipResolver` is `final readonly`,
holds a repository and nothing request-derived, takes the frontend user id as an
argument on every call, and already refuses to compare a non-positive id — the
"deny before compare" rule stated below, applied one layer down so that it holds
even for a caller that skips the rule service.

The rule is a single `final readonly` service, stateless per repository policy,
with `Context` injected and the aspect resolved on every call. It exposes both
shapes:

- a `bool` variant, which is what a v14 `#[Authorize(callback: …)]` needs, and
- an `assert*` variant throwing `AccessDeniedException`, which is what a v13
  controller needs.

Two properties of that service are load-bearing rather than stylistic. It
**denies before it compares** — a record whose owner column is `0`, written by
an editor or by a bug, would otherwise be owned by every anonymous visitor. And
it has **no repository dependency**, so the rule is covered by unit tests
without persistence.

### The v14 attribute is an adapter, not the rule

v14.2 introduced `#[Authorize]`
(Feature #107826,
`Changelog/14.2/Feature-107826-IntroduceExtbaseActionAuthorizationLogic.rst`,
attribute at `cms-extbase/Classes/Attribute/Authorize.php:20-34`); v13.4 has
neither the attribute nor `ActionAuthorizationService`. Per the repository rule
that version differences split classes, the split is:

| Location             | Contents                                                               |
|----------------------|------------------------------------------------------------------------|
| `Classes/Security/`  | The rule service. Identical on both versions, used unchanged by both.  |
| `Core14/Controller/` | Controller carrying `#[Authorize(requireLogin: true)]` and a callback. |
| `Core13/Controller/` | Same controller, asserting ownership as the first statement.           |

The shared abstract base holds everything else and uses `#[Required]`
`inject*()` methods, so the duplication is the guard line and nothing more.

Three details worth writing down before that code exists:

1. **`#[Authorize]` runs after property mapping and validation**, inside
   `callActionMethod()` (`cms-extbase/Classes/Mvc/Controller/ActionController.php:465-479`),
   while `initializeAction()` and `initialize<Foo>Action()` run *before* it
   (`:369-374`). **No state change may happen in an `initialize*Action()`** — it
   executes for a caller who is about to be rejected with 403.
2. The callback class must be **public in the container**
   (`#[Autoconfigure(public: true)]`). That is an explicit exception to
   "services are private", forced by the framework, and it is documented as
   such where it is used.
3. Denial produces a 403 through `PropagateResponseException`
   (`ActionController.php:998-1007`); the read endpoints do not use it, see
   [profile enumeration](#the-checklist).

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
3. Select the child *within* that aggregate by the client-supplied uid, by
   iterating the parent's `ObjectStorage` and comparing `getUid()`, or with
   `ObjectStorage::contains()` when the object is already held
   (`cms-extbase/Classes/Persistence/ObjectStorage.php:214`).
4. A uid that is not in the set produces the same response as a uid that does
   not exist. That indistinguishability is the point.

This is stated as a rule a patch can be reviewed against:

| Reviewable statement                                                         | How it is checked                                                              |
|------------------------------------------------------------------------------|--------------------------------------------------------------------------------|
| No `->findByUid(` anywhere in the AJAX controller or its base class.         | grep, and a gate-sized assertion in review.                                    |
| No `__identity` mapping is configured for any child argument.                | grep for `__identity` and `CONFIGURATION_MODIFICATION_ALLOWED`.                |
| The only repository entry point takes a frontend user id, not a record uid.  | Read the repository's public method signatures.                                |
| A client uid appears only as a filter argument, never as a query constraint. | Follow every request-derived variable to its use.                              |
| Reads keep `setRespectStoragePage(true)`.                                    | `Typo3QuerySettings.php:55` is the default — the review looks for an override. |

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

| Risk                                                 | Defence                                                                                                      | Where it belongs                                                       |
|------------------------------------------------------|--------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------|
| IDOR on child records (address, e-mail, image)       | Navigate the owned aggregate; the client uid filters, it never looks up. No `findByUid()` in the controller. | Repository entry point + controller; enforced by test, per child type. |
| Mass assignment (no `__trustedProperties` in JSON)   | A `final readonly` DTO with exactly the writable fields. Never `allowAllProperties()`.                       | `Classes/Domain/Dto/`; controller maps DTO onto the resolved entity.   |
| `pid` escape (`profile[pid]=1`)                      | `pid` is not a DTO property and never appears in an allow-list; new records inherit the parent's `pid`.      | DTO shape; the factory that creates records.                           |
| Profile enumeration via the read endpoint            | The read endpoint takes **no identifier** and returns the caller's profile. Uniform 404, never 403.          | Action signature; one `notFoundResponse()` helper on the base class.   |
| Hidden-record toggling (`profile[hidden]=0`)         | `hidden`, `starttime`, `endtime`, `deleted` are not DTO properties; publishing is an explicit action.        | DTO shape; a dedicated action with its own ownership assertion.        |
| Enable-field bypass leaking editor-disabled records  | `setEnableFieldsToBeIgnored(['disabled'])` on the **owner-constrained** query only, never repository-wide.   | A dedicated repository method; never `initializeObject()`.             |
| Global visibility bypass                             | `VisibilityAspect` is never touched — it is request-global and would un-hide other people's records too.     | Nowhere. This is a "do not".                                           |
| Cross-user leak through the group-keyed page cache   | The endpoint page type is never cached; the edit plugin markup is `USER_INT`.                                | TypoScript of the endpoint page type; plugin registration.             |
| Silent live-record write while a workspace is active | Refuse the write: `Context` `workspace.isLive` is asserted before any persistence call.                      | A guard service, called by the write path, not by each action.         |

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
record exists and belongs to somebody else. The read endpoint removes the
question by taking no identifier at all; where a child selector is unavoidable,
"not in my set" and "does not exist" return the same status *and the same body*,
produced by one helper so no action can deviate.

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

**The chosen transport removes the shape rather than defending against it.** The
endpoint page type disables caching through the cache instruction on the request
(`frontend.cache.instruction`, Feature #102628), so no entry is ever written for
it, and the edit plugin markup on the surrounding page is `USER_INT` so it is
not part of a cached page either. The v14 authorization changelog makes the same
point from the other side: a custom authorization response must only be used for
uncached actions, or it is cached and served regardless of the caller's status.

The residual work is small and still required: every AJAX action is registered
as non-cacheable (`cms-extbase/Classes/Core/Bootstrap.php:242-247`) and the
responses carry `Cache-Control: private, no-store`. Without the test named
below, a regression here is invisible in development, because a single developer
is always their own cache entry.

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
`WorkspaceWritesNotSupportedException` from the guard service, which the write
path calls once — not each action, which would make it forgettable.

## How the rules are tested

The resolver is covered today by
`Tests/Functional/Security/ProfileOwnershipResolverTest.php`: the owned set
includes the owner's *hidden* profiles, never contains another user's profile,
is empty for an anonymous caller, and an ownerless profile belongs to nobody.
Everything in the table below belongs to the write endpoints and is written with
them.

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
| Profile enumeration    | Anonymous and non-owner requests return the identical status **and body**; the read endpoint accepts no identifier.                    |
| Hidden-record toggling | A payload carrying `hidden` does not change the stored `hidden`; the dedicated publish action does, for the owner only.                |
| Enable-field bypass    | An editor-disabled record of *another* user is absent from every response of the owner-constrained endpoint.                           |
| Group-keyed cache leak | Two users **in the same group** request the endpoint in one test; the bodies differ. This is the test that catches a re-enabled cache. |
| Workspace guard        | A request with `withWorkspaceId()` set is refused, and the live row is byte-identical afterwards.                                      |

Per repository policy every one of these is **shown to fail** with its guard
removed before it is committed — an authorization test that passes for the wrong
reason is worse than no test, because it is trusted.

## See also

- [Plugins and the Fluid layer](plugins-and-fluid.md) — the ownership flag the
  read plugins render, and why it is not one of the defences on this page.
- [Class design](../architecture/class-design.md)
- [Dependency injection](../architecture/dependency-injection.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [Site based tests](../testing/site-based-tests.md)
- [Functional tests](../testing/functional-tests.md)
