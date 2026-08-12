# Plugins and the Fluid layer

The read side of the feature is two plugins — a **list** of the visible profiles
and a **show** view of one of them — plus the Fluid layer they render through.
Both exist in `Classes/Controller/ProfileController.php`,
`Configuration/Sets/Profiles/`, `Configuration/TCA/Overrides/tt_content.php`,
`ext_localconf.php` and `Resources/Private/`.

This page records three things that are easy to get wrong: the plugin
registration call that is correct on both core versions, how the site set and
the classic TypoScript relate, and the contract of the Fluid partials.

A third plugin — the **edit** surface — is registered the same way and reuses
four of the partials described here. Everything specific to it, including why it
has a controller of its own, is [The edit plugin](edit-plugin.md).

> [!IMPORTANT]
> **The `editable` flag that reaches the templates is a display decision, not an
> authorization boundary.** It decides whether an edit link is drawn. A link that
> is not drawn is still reachable by typing the URL, so hiding it protects
> nothing. The boundary lives on the write endpoints, which resolve the frontend
> user from the session and navigate the owned aggregate — see
> [Authorization](authorization.md). Do not reuse this flag as a guard, and do
> not drop a guard elsewhere because the flag exists.

## What registers what

| File                                         | Call                                  | Effect                                                                                       |
|----------------------------------------------|---------------------------------------|----------------------------------------------------------------------------------------------|
| `ext_localconf.php`                          | `ExtensionUtility::configurePlugin()` | Controller/action map, the non-cacheable actions, and the `EXTBASEPLUGIN` TypoScript object. |
| `Configuration/TCA/Overrides/tt_content.php` | `ExtensionUtility::registerPlugin()`  | The three `CType` values and their entries in the content element wizard.                    |

The plugin signature — and therefore the `CType` — is
`strtolower($extensionName) . '_' . strtolower($pluginName)`
(`ExtensionUtility.php:125`), so the three content elements are
`modernextbasefrontendedit_list`, `modernextbasefrontendedit_show` and
`modernextbasefrontendedit_edit`.

`configurePlugin()` is called a fourth time, for the `Ajax` plugin, and
`registerPlugin()` deliberately is not: the endpoints are an endpoint, not
something an editor places on a page, and `configurePlugin()` adds no TCA by
itself. → [AJAX transport](ajax-transport.md)

The order of the two files matters on v13 and is the natural one:
`ext_localconf.php` is loaded before the TCA overrides, v13's `configurePlugin()`
stores the plugin type in `$GLOBALS` (`:90`), and v13's `registerPlugin()` reads
it back from there, falling back to `list_type` when nothing was registered
(`:147`). Moving the registration out of `ext_localconf.php` would therefore
re-introduce the deprecation on v13 through a file that does not mention it. On
v14 there is nothing to read back: the parameter is gone from `registerPlugin()`
(`ExtensionUtility.php:119`).

`registerPlugin()` is called with six arguments, which is the portable call:
v13.4's signature ends at `$pluginDescription` while v14 added a seventh
`$flexForm` parameter. Both are positional, so a call that stops at the sixth is
identical on both.

v13.4 line numbers on this page refer to `typo3/cms-extbase` 13.4; v14 numbers
are from the installed set below `.Build/vendor/`.

## `PLUGIN_TYPE_CONTENT_ELEMENT` is the only call correct on both versions

The fifth parameter of `configurePlugin()` is the one place in this change where
the two core versions genuinely disagree, and the disagreement is not symmetric:
v13 makes the *wrong* value the default, v14 removed the constant that names it.

| Fifth parameter               | TYPO3 v13.4                                                  | TYPO3 v14.3                                                           |
|-------------------------------|--------------------------------------------------------------|-----------------------------------------------------------------------|
| omitted                       | defaults to `list_type` (`:55`), `E_USER_DEPRECATED` (`:57`) | defaults to `'CType'` (`ExtensionUtility.php:52`), no diagnostic      |
| `PLUGIN_TYPE_PLUGIN`          | the constant exists, is deprecated, same `E_USER_DEPRECATED` | the constant does not exist — referencing it is a PHP `Error`         |
| `PLUGIN_TYPE_CONTENT_ELEMENT` | accepted, no diagnostic                                      | accepted, the only accepted value                                     |
| anything else                 | `\InvalidArgumentException` 1289858856 (`:87-88`)            | `\InvalidArgumentException` 1730801526 (`ExtensionUtility.php:53-55`) |

Verified against the installed v14 tree:
`PLUGIN_TYPE_CONTENT_ELEMENT = 'CType'` is the only constant left on the class
(`.Build/vendor/typo3/cms-extbase/Classes/Utility/ExtensionUtility.php:28`), the
parameter is `?string $pluginType = null` (`:47`), `null` is coalesced to it
(`:52`) and every other value throws (`:53-55`). The removal of
`ExtensionUtility::PLUGIN_TYPE_PLUGIN` is listed in Breaking **#105377
"Deprecated functionality removed"** (14.0); the v13 side is Deprecation
**#105076 "Plugin content element and plugin sub types"** (13.4), which states
that the parameter "is still the default" and that omitting it or passing
`list_type` triggers a deprecation level log entry.

That deprecation is not a cosmetic one here:
[our suites fail on deprecations](../testing/phpunit-configuration.md#strictness-policy),
so the v13 default would turn a functional run red. Naming
`PLUGIN_TYPE_CONTENT_ELEMENT` explicitly is therefore not defensive style — it
is the single spelling that is silent on v13 and legal on v14, which is why
`ext_localconf.php` needs no core version switch for it.

### Both actions are registered non-cacheable

`configurePlugin()` receives the same controller/action map twice: once as the
allowed actions, once as the non-cacheable ones. The reason is the ownership
flag. The page cache identifier varies by frontend user **group ids, not by user
uid** (`PrepareTypoScriptFrontendRendering.php`), so two members of one group
share a cache entry and a cached rendering carrying user A's edit links would be
served to user B. Extbase reads the non-cacheable list back in
`Bootstrap::isExtbaseRequestCacheable()` and renders the plugin as `USER_INT`,
which removes the question rather than defending against it.
→ [The group-keyed page cache](authorization.md#the-group-keyed-page-cache)

The edit plugin's single action is registered the same way, for a sharper
version of the same reason: its markup carries a request token signed with a
per-browser nonce *and* the whole profile document, so a cached rendering would
hand user B the token and the profile of user A.
→ [The edit plugin](edit-plugin.md#what-is-registered-and-how)

## A site set **and** classic TypoScript

The extension ships both, and that is a deliberate duplication rather than a
transitional state.

**Site sets are not a v14 feature.** They were introduced by Feature **#103437
"Introduce site sets"** in TYPO3 **13.1**, so they are available on both target
versions. What they are not is universal: a set applies only to a site that
lists it in `config.yaml`, and an installation that still configures its sites
through `sys_template` records never sees it. Core's own `felogin` ships both for
exactly that reason: a set under `Configuration/Sets/Felogin/` next to
`addTypoScriptConstants()` and `addTypoScriptSetup()` in its `ext_localconf.php`.

The two carry the same five settings in two namespaces:

| Meaning                              | Site set setting (`settings.definitions.yaml`)     | Classic TypoScript constant                                      | Plugin path                                                      |
|--------------------------------------|----------------------------------------------------|------------------------------------------------------------------|------------------------------------------------------------------|
| Storage pages of the profile records | `modernextbasefrontendedit.persistence.storagePid` | `plugin.tx_modernextbasefrontendedit.persistence.storagePid`     | `plugin.tx_modernextbasefrontendedit.persistence.storagePid`     |
| Page holding the show plugin         | `modernextbasefrontendedit.showPageUid`            | `plugin.tx_modernextbasefrontendedit.settings.showPageUid`       | `plugin.tx_modernextbasefrontendedit.settings.showPageUid`       |
| Page holding the edit plugin         | `modernextbasefrontendedit.editPageUid`            | `plugin.tx_modernextbasefrontendedit.settings.editPageUid`       | `plugin.tx_modernextbasefrontendedit.settings.editPageUid`       |
| Page **type** of the JSON endpoints  | `modernextbasefrontendedit.ajaxPageType`           | `plugin.tx_modernextbasefrontendedit.settings.ajaxPageType`      | `plugin.tx_modernextbasefrontendedit.settings.ajaxPageType`      |
| Folder uploaded images are stored in | `modernextbasefrontendedit.imageUploadFolder`      | `plugin.tx_modernextbasefrontendedit.settings.imageUploadFolder` | `plugin.tx_modernextbasefrontendedit.settings.imageUploadFolder` |

`ajaxPageType` is the odd one: a page *type*, not a page uid, because the
endpoints answer on whichever page the edit plugin sits on. It also feeds
`view.formatToPageTypeMapping.json`, and the set restates it on the endpoint
`PAGE` object's `typeNum` — a site setting cannot reach a TypoScript *constant*,
so that one line is what makes the site setting win on a site using the set. The
edit plugin reads it to build its endpoint map, and a value of `0` is what makes
that map empty and the surface refuse to enhance.
→ [Degradation](edit-plugin.md#degradation)

`imageUploadFolder` is a **combined storage identifier** — `1:/user_upload/profiles/`
by default — and not a path: `FileUploadConfiguration::ensureValidConfiguration()`
throws 1711801071 for anything else, and the endpoint calls it early so that a
typo in the site configuration surfaces at the request that configured it. The
folder is created on the first upload. It is a setting rather than a constant
because the storage layout of an installation is not this extension's to assume,
and it has a default so that a site which configures nothing still works.
→ [Image handling](image-handling.md)

Three things follow from that layout:

1. **Set settings are flat, plugin configuration is not.** Every site setting
   becomes a TypoScript constant of exactly its key
   (`SysTemplateTreeBuilder::addDefaultTypoScriptConstantsFromSite()`), so the
   set's `setup.typoscript` is the file that maps them onto
   `plugin.tx_modernextbasefrontendedit.*`. The keys are prefixed with the
   compact extension key rather than a hand-written camel-cased name, because
   that is the same token the Extbase TypoScript namespace is built from.
2. **The defaults live in one place.** `settings.definitions.yaml` declares them
   for the set; `addTypoScriptConstants()` declares them for the classic path.
   `setup.typoscript` repeats no value.
3. **The order is defaults first, set second.** `addTypoScriptSetup()` leaves its
   second argument at the default `true`, so the classic statements are also
   added to the `siteSets` scope, which is included *before* the sets of a site
   (`SysTemplateTreeBuilder::createSiteTemplateInclude()`). A site using the set
   gets the set's values; a site that does not gets the classic defaults.

The `mvc` block exists only in the classic TypoScript, because it carries no
site setting and is identical on every site:
`showPageNotFoundIfTargetNotFoundException` and
`showPageNotFoundIfRequiredArgumentIsMissingException` (Feature #104321, v13.3)
turn a missing or unresolvable `profile` argument of the show plugin into the
site's configured 404 instead of an exception page.

Storage configuration has one sharp edge worth repeating where an integrator
looks: **there is no "all pages" value.** Extbase turns an empty configuration
into the page id list `[0]` (`QueryFactory::create()`), and profiles do not live
on the root level, so an unconfigured plugin lists nothing.

No `view.templateRootPaths` is configured, deliberately. `ActionController`
prepends `EXT:<extension key>/Resources/Private/{Templates,Layouts,Partials}/`
to whatever is configured (`ActionController.php:529-531`), so the convention
paths work without configuration and an integrator's own paths still win.

## The Fluid layer

| File                                | Role                                                                                                                  |
|-------------------------------------|-----------------------------------------------------------------------------------------------------------------------|
| `Layouts/Default.html`              | The single wrapper element of all three plugins, one `Main` section.                                                  |
| `Templates/Profile/List.html`       | Composes the list: heading, empty state, one card per entry.                                                          |
| `Templates/Profile/Show.html`       | Composes the detail view: card, details, addresses, e-mails.                                                          |
| `Templates/ProfileEdit/Edit.html`   | The edit plugin: four states, the custom element, and the four `data-` attributes.                                    |
| `Partials/Profile/Card.html`        | The identifying block: image, name, and the links that apply to a profile.                                            |
| `Partials/Profile/Details.html`     | The scalar fields that are not part of the card: birthday and biography.                                              |
| `Partials/Profile/AddressList.html` | The postal addresses including their section heading.                                                                 |
| `Partials/Profile/EmailList.html`   | The e-mail addresses including their section heading.                                                                 |
| `Partials/Profile/EditLink.html`    | The edit link, and the only place in the Fluid layer that reads the ownership flag.                                   |
| `Partials/Profile/Image.html`       | The profile image, or nothing.                                                                                        |
| `Partials/Profile/OwnerView.html`   | One profile as its owner sees it, hidden children included. Rendered by the edit plugin in both of its record states. |

Four rules hold across all of them.

**Partials receive explicit arguments and never `_all`.** Every `<f:render>`
lists every argument the partial consumes. `_all` passes whatever the caller
happens to have in scope, which makes the partial's real input set invisible and
turns any variable rename in a controller into a silent template change. The
argument list at the head of each partial is therefore the whole contract, and
it is checkable by grep.

**URIs are built in templates, not in partials.** Only a template knows which
plugin it links to. `Profile/Card` receives `showUri` and `editUri` as finished
strings and carries no knowledge of a plugin, controller or action name. A page
uid of `0` means "not configured" and is guarded in the template rather than
passed on, because both `f:uri.page` and `f:uri.action` resolve a page uid of
`0` to the *current* page, which for the list plugin would be a link to itself.

> [!NOTE]
> **Correction, twice over.** An earlier revision of this page argued that the
> URI rule is what would keep `Profile/Card` usable from the edit plugin, which
> would "pass its own URIs and change nothing here". The edit plugin does not use
> `Profile/Card` at all: the card bundles the image and the name with the links
> that apply to a profile, and links are not part of an editing surface. It
> reuses `Profile/Details`, `AddressList`, `EmailList` and `Image` through
> `Profile/OwnerView`, which renders its own heading. The rule itself held; the prediction about which partial it
> would pay off in did not.
>
> The reason given for the split was wrong as well, and it was corrected once
> the image became editable. It said the image and the name belong on
> **different sides** of the custom element — the image outside, where nothing
> could make it disagree with the server. With an upload endpoint something can:
> everything outside the element survives the upgrade unchanged, so a copy left
> there would keep showing the file the page was loaded with next to a surface
> already showing the new one. Both are rendered **inside**.
> → [Image handling](image-handling.md#the-image-is-rendered-inside-the-custom-element)

**The heading level is an argument.** `Profile/Card` renders its name heading
into `{headingTag}`, and both call sites pass a different value: the list
template carries its own `h2` and puts `h3` on the cards below it, while the show
template spends the `h2` on the card itself. Only the caller knows the document
structure it is building, so a partial that hardcoded a level would be correct in
exactly one of the two places.

**No business logic, and no literal text.** Anything that needs deciding is
decided in the controller and arrives as data — the ownership flag is the whole
example. Every user-visible string comes from
`Resources/Private/Language/locallang.xlf`, except the address and e-mail *type*
labels, which are select item labels and are looked up dynamically in
`locallang_db.xlf` so that a type added to the TCA later needs no template
change. The editing surface labels the same six values from `locallang.xlf`
instead, which is a real duplication and is
[recorded as one](edit-plugin.md#the-six-choice-labels-exist-twice).

### The partial API

Every argument is required in the sense that the call site must pass it; where a
partial accepts an "absent" value, that value is an empty string or `null` and
the partial renders nothing. That is deliberate: an optional argument would let a
call site forget one and still render, which is the failure mode these partials
are shaped to avoid.

#### `Profile/Card`

| Argument     | Type                   | Required | Meaning                                                                          |
|--------------|------------------------|----------|----------------------------------------------------------------------------------|
| `profile`    | `Domain\Model\Profile` | yes      | The profile to render.                                                           |
| `editable`   | `bool`                 | yes      | Display-only ownership flag as the controller computed it; passed to `EditLink`. |
| `headingTag` | `string`               | yes      | Tag name of the name heading, `h2` or `h3`.                                      |
| `showUri`    | `string`               | yes      | Finished URI of the show plugin, or empty for no link.                           |
| `editUri`    | `string`               | yes      | Finished URI of the edit page, or empty when none is configured.                 |

Does not know: which plugin called it, any plugin, controller or action name, or
what the surrounding document structure is. Its root element is a `div` rather
than an `article`, so the caller decides the semantics — the list wraps each card
in a list item, the show template wraps the whole profile in an `article`.

The name is `firstname lastname`, falling back to `shortname` when a profile
carries neither, because `shortname` is the only guaranteed label. Without that
fallback the heading and the image alternative text would be empty.

#### `Profile/Details`

| Argument  | Type                   | Required | Meaning                                      |
|-----------|------------------------|----------|----------------------------------------------|
| `profile` | `Domain\Model\Profile` | yes      | Read for `birthday` and `bio`, nothing else. |

Does not know: whether anything is rendered around it. It emits nothing when both
fields are empty, so no empty description list can reach the output, and the
caller never has to ask first.

`f:format.date` is used without a `format` argument on purpose: the format then
comes from the installation-wide `SYS/ddmmyy` setting, which keeps the date
format an integrator decision rather than one made in a template.

#### `Profile/AddressList` and `Profile/EmailList`

| Argument    | Type                             | Required | Meaning                                  |
|-------------|----------------------------------|----------|------------------------------------------|
| `addresses` | `iterable<Domain\Model\Address>` | yes      | `profile.addresses`. `AddressList` only. |
| `emails`    | `iterable<Domain\Model\Email>`   | yes      | `profile.emails`. `EmailList` only.      |

Both render their own section heading and nothing at all when the collection is
empty. The iteration order is the manual sorting order, because the
`ObjectStorage` is returned live.
→ [Persistence and sorting](persistence-and-sorting.md)

Does not know: which profile the collection belongs to. Passing the collection
rather than the profile is what makes the partial reusable for a collection that
was assembled elsewhere — which is exactly what the edit plugin does, since it
loads children through their own repositories instead of off the parent.

Both mark a **hidden** record as hidden. The read plugins never pass one — their
collections come off the parent and are reconstituted with enable fields applied
— so the marker is invisible there. The edit plugin does pass them, precisely so
the owner sees the records they hid, and an unmarked hidden address in that view
would read as published. This is the one place where a partial renders something
only one of its three call sites can ever trigger, and it is worth knowing that
the read plugins are not what covers it.

`EmailList` uses `f:link.email` rather than a hand-written `mailto:` href,
because it honours the installation's `spamProtectEmailAddresses` configuration,
which a literal href would bypass.

#### `Profile/EditLink`

| Argument   | Type     | Required | Meaning                                                            |
|------------|----------|----------|--------------------------------------------------------------------|
| `editable` | `bool`   | yes      | The display-only ownership flag. The Fluid layer never derives it. |
| `editUri`  | `string` | yes      | Finished URI of the edit page; empty renders nothing.              |

Does not know: what ownership *is*. It renders when both arguments are truthy and
otherwise stays silent. This is the only place in the Fluid layer that reads the
flag, which is what makes the display-versus-authorization statement at the top
of this page checkable rather than aspirational.

#### `Profile/Image`

| Argument          | Type                              | Required | Meaning                                                                     |
|-------------------|-----------------------------------|----------|-----------------------------------------------------------------------------|
| `image`           | `Domain\Model\ProfileImage`, null | yes      | The read-side value object, `profile.profileImage`. `null` renders nothing. |
| `alternativeText` | `string`                          | yes      | Used when the file reference itself carries no alternative text.            |

Does not know: FAL. It reads scalars off the value object and nothing else, and
renders nothing when there is no public URL. There is deliberately no `f:image`:
that ViewHelper needs the Extbase file reference and would pull FAL processing
into a partial that only displays. An extension that wants processed images
replaces this one partial and nothing else — which is why the image is a partial
of its own rather than three lines inside the card.

It has two call sites: `Profile/Card`, which the list and detail templates
render, and the edit template, which renders it **inside** the custom element
because the image is editable there. That last one is the no-JavaScript view of the image; once the
element upgrades, the component draws its own.
→ [Image handling](image-handling.md#the-image-is-rendered-inside-the-custom-element)

#### `Profile/OwnerView`

| Argument      | Type                               | Required | Meaning                                                                    |
|---------------|------------------------------------|----------|----------------------------------------------------------------------------|
| `profile`     | `Domain\Model\Profile`             | yes      | The record. The image is read off it as `profile.profileImage`.            |
| `profileName` | `string`                           | yes      | Heading and image alternative text, shortname fallback already applied.    |
| `addresses`   | iterable of `Domain\Model\Address` | yes      | Read through the **edit** repository by the caller, so hidden rows are in. |
| `emails`      | iterable of `Domain\Model\Email`   | yes      | The same.                                                                  |

The composition the edit plugin renders: heading, image, details, both
collections. It is the only partial that is itself made of four partials, and it
exists for one reason — the edit template renders it **twice**, once inside the
custom element as the no-JavaScript view and once on its own when a workspace is
active, and two copies of that block would drift.

Does not know: whether it is editable. That is the caller's branch, and nothing
in here changes between the two call sites — which is the property that makes one
partial correct for both.
→ [The edit plugin](edit-plugin.md)

### Why the contract is a comment block and not `<f:argument>`

`<f:argument>` would turn the documented argument list into an enforced one, and
the ViewHelper is present on both target versions — v14 ships it in
`typo3fluid/fluid` 5.3.1
(`.Build/vendor/typo3fluid/fluid/src/ViewHelpers/ArgumentViewHelper.php:95`) and
`typo3/cms-fluid` 13.4 requires `typo3fluid/fluid ^4.6.1`, where the class is
byte-identical apart from its docblock. What is *not* the same on both versions
is the type language it validates against:

- Validation happens per declared argument in
  `AbstractTemplateView::processAndValidateTemplateVariables()` (`:417-451`), and
  a value that is present but of the wrong type raises
  `InvalidArgumentValueException` 1746637333.
- `StrictArgumentProcessor::isValid()` (`:46-65`) accepts `null` **only** for an
  argument that is not required, and resolves the declared type through
  `ArgumentDefinition::getUnionTypes()`.
- Union types — the spelling that would express `ProfileImage|null` — are Fluid
  **5.0** and later, which means TYPO3 v14 only. The ViewHelper's own docblock
  records that as `versionchanged:: Fluid 5.0`.

So `Profile/Image`'s `image` argument has no declaration that is both accurate
and identical on the two versions: declaring it required rejects the legitimate
`null`, declaring it optional says something the call sites do not mean, and the
union spelling is v14-only. Declaring four partials and documenting the fifth
would leave the enforcement inconsistent, so all five document. The comment block
is checked by review and by the call sites, not by the engine — a deliberate
limitation, and the one to revisit when v13 support is dropped.

## What this layer deliberately does not do

- **The edit link carries no profile argument, and now never will.** It points at
  the configured page and stops there. The edit plugin takes no arguments at
  all: it resolves the record from the session, so there is nothing for a link
  to say. What read as a deferral in an earlier revision of this page turned out
  to be the final shape.
- **The show template has no "back to the list" link.** No setting names the page
  the list plugin sits on, and guessing it — the referrer, the current page —
  would be wrong on some installations. It is added when a setting for it is,
  together with its label. The edit template carries the same gap for a link to
  a login page, for the same reason.
- **The layout declares no `HeaderAssets`/`FooterAssets` sections.** Their
  automatic rendering is deprecated since v14.0 (changelog #107057) and stops
  working in v15.0. The edit template loads its stylesheet and its module with
  `f:asset.css` and `f:asset.module`, and no inline script is emitted anywhere.
  → [Frontend assets](frontend-assets.md)
- **No image processing, no cropping, no responsive variants.** See
  `Profile/Image` above. The file is served as it was uploaded, and an extension
  that wants processed images replaces that one partial.
  → [Image handling](image-handling.md#named-gaps)

## See also

- [Modern frontend editing](Index.md) — the other pages of this design.
- [The edit plugin](edit-plugin.md) — the third plugin, its controller, and the
  four partials it reuses from here.
- [Authorization](authorization.md) — where the real boundary lives, and the
  group-keyed page cache this registration works around.
- [Domain and schema](domain-schema.md) — the tables and TCA behind the models
  the templates render.
- [Persistence and sorting](persistence-and-sorting.md) — why the collections
  come back in the editor's order, and why the edit flow does not read them off
  the parent.
- [Image handling](image-handling.md) — what `profile.profileImage` is and why
  it exposes scalars only.
- [Core version aware code](../architecture/core-version-aware-code.md) — the
  configuration exception this registration did not need.
