# The edit plugin

The third plugin, and the first one that writes. It renders the profile of the
calling session as readable markup, hands a lit component four `data-`
attributes, and the component turns that markup into an editing surface that
talks to [the seven endpoints](ajax-transport.md#the-seven-endpoints) — six of
them, to be exact.

Everything on the server side is
`Classes/Controller/ProfileEditController.php`,
`Classes/Http/ProfileDocumentFactory.php` and
`Resources/Private/Templates/ProfileEdit/Edit.html`. Everything on the browser
side is `Build/Sources/TypeScript/`, whose `component/` directory holds the two
custom elements and whose `model/` and `api/` directories hold the logic that is
covered without a browser.

## What is registered, and how

| File                                         | Call                                  | Effect                                                                                |
|----------------------------------------------|---------------------------------------|---------------------------------------------------------------------------------------|
| `ext_localconf.php`                          | `ExtensionUtility::configurePlugin()` | The `Edit` plugin, its single `edit` action, and that action listed as non-cacheable. |
| `Configuration/TCA/Overrides/tt_content.php` | `ExtensionUtility::registerPlugin()`  | The `CType` `modernextbasefrontendedit_edit` and its wizard entry.                    |

The registration follows the two read plugins in every respect that
[Plugins and the Fluid layer](plugins-and-fluid.md#what-registers-what)
documents — the same `PLUGIN_TYPE_CONTENT_ELEMENT` fifth argument, the same file
order, the same six-argument `registerPlugin()` call. Two things are specific to
it:

- **It takes no arguments.** There is no `profile` argument and no flexform. The
  record is resolved from the session, so an editor placing the content element
  cannot configure *which* profile it shows and does not have to. One placement
  on the page named by `editPageUid` serves every logged-in visitor their own
  profile.
- **Non-cacheable is not the ownership argument this time.** The read plugins are
  `USER_INT` because the edit *link* depends on the user while the page cache is
  keyed by user *groups*. Here the markup carries a request token signed with a
  **per browser** nonce and the whole profile document, so a cached rendering
  would hand user B the token *and* the profile of user A. `USER_INT` removes
  the question rather than defending against it.
  → [The editing plugin is `USER_INT`](ajax-transport.md#the-editing-plugin-is-user_int)

The assets survive `USER_INT`: `<f:asset.css>` and `<f:asset.module>` are
collected while the non-cached pass renders, and
`PageRenderer::renderJavaScriptAndCssForProcessingOfUncachedContentObjects()`
(`cms-frontend/Classes/Http/RequestHandler.php:300-307`) re-runs the whole
JavaScript and CSS rendering into the placeholders of the cached page — the
import map included. `ProfileEditPluginTest::theAssetsOfTheEditingSurfaceAreEmitted()`
asserts both tags and the absence of a leftover `<!-- ###JS_LIBS` marker,
because a missing tag and an unsubstituted placeholder are different defects.

The template branches on two booleans the controller assigns and derives
nothing:

| `authenticated` | `profile` | What is rendered                                                                |
|-----------------|-----------|---------------------------------------------------------------------------------|
| `false`         | `null`    | One sentence: log in first. No element, no assets.                              |
| `true`          | `null`    | A different sentence: you have no profile yet. No element, no assets.           |
| `true`          | a profile | The custom element with its four attributes, the assets, and the image outside. |

The two empty states are separate sentences on purpose. "Log in" and "you have
no profile yet" are different instructions, and one vague sentence covering both
is actionable for neither visitor. An anonymous visitor is deliberately **not**
an error: the plugin sits on a page a site may link from anywhere, and a 403 or
an exception page is a worse answer than a sentence. The authorization boundary
is on the write endpoints and is unaffected by what this action renders.
→ [Authorization](authorization.md)

## Why this is not an action on `ProfileController`

`ProfileController` asserts in its own docblock that it reads through the
display repository and *never* through the `Edit\` counterparts. This action has
to do the opposite: **the owner's editing view must show the children the owner
has hidden.** Those are exactly the records the plugin exists to let the owner
find again and publish, and they are unreachable through the parent — relations
are reconstituted with query settings built from scratch
(`DataMapper::getPreparedQuery()`), so `$profile->getAddresses()` never contains
them.
→ [The display and edit repository split](persistence-and-sorting.md#the-display-and-edit-repository-split)

Adding the action to the read controller would therefore have meant injecting
two edit repositories into it and falsifying the invariant its docblock asserts.
A second controller costs one class; a read controller that can reach hidden
records costs the ability to reason about the read path at all.

The class is `final` but not `readonly`, because `ActionController` is not.

The profile itself is resolved through `ProfileOwnershipResolverInterface` and
reduced to the **lowest uid** of the owned set — the same rule
`ProfileAjaxController::resolveOwnedProfile()` applies when a payload carries no
`uid`. The two must agree: the component sends the uid it was rendered with back
to the endpoints, and a disagreement would answer `404` on every write.

## The two editing modes

Both are the same edit session with a different `mode`, held in one map keyed by
record — `profile`, `address:7`, `email:new`. What differs is what is sent.

### Per-field, inline

The affordance is per field: *Edit* switches one field to a control, *Apply*
sends it, *Cancel* discards it. Several fields of one record may be open at
once, each with its own draft and its own errors.

**Apply sends only that field.** The payload of `saveField` is the record
identity plus one `field` and one `value`, and nothing else — which is what
makes it a partial save rather than a full save that happens to change one
value. The endpoint validates that one property against the same rule set a full
save uses.
→ [Full versus partial validation](dto-and-validation.md)

**Cancel reverts to the last server-known value, not to the value at page
load.** Those differ as soon as one save has succeeded, and the difference is
the whole reason the drafts do not live in the DOM. Cancelling drops the draft;
the field then renders from `profile`, which is the document of the most recent
successful response. A user who saves *Ada*, edits again, types *Adelaide* and
cancels gets *Ada* back — not whatever the page was loaded with. `editState.test.ts`
covers exactly that case, and its docblock says which assertion would be wrong.

Enter applies and Escape cancels. Enter is not bound in a textarea, where it is
a newline and where taking it away would make a biography a single line; Escape
is bound everywhere, because a control a user cannot leave with the keyboard is
a trap.

### Whole record

*Edit all fields* opens every writable field of one record at once and submits
them together through `save`. It **replaces** whatever single-field session was
open on that record rather than merging with it: a whole record edit is a
different intent, and mixing it with half-finished drafts would submit values
the user never saw together. The per-field Apply and Cancel buttons are not
rendered in this mode — a second pair meaning something narrower, next to the
record's own pair, is how a user saves one field while believing they saved
five. The keyboard still works, and answers by submitting or cancelling the
record.

### The add form

The form that creates a child is a whole-record session under a target whose
`childUid` is `null`. Its fields are always open and carry no per-field apply,
because there is nothing to apply *to* until the record exists. It uses the same
session machinery as everything else, so a `422` from `addChild` lands at the
field exactly like one from a save.

Removing, reordering and toggling the visibility of a child have no controls to
open at all. They still create a session on demand — that is the only place a
failure of theirs can be reported.

A move sends the **whole** resulting order, never a delta, because the reorder
endpoint replaces the collection and deletes every record the submitted list
omits. A move that would leave the collection produces the unchanged order and
the request is then not sent at all.
→ [Persistence and sorting](persistence-and-sorting.md)

## The enhanced surface is client-rendered

The element does **not** enhance the server markup in place. Once it upgrades it
renders its own surface into its shadow root and does not slot its light DOM
children, so the server-rendered view disappears in favour of the editable one.

This was the contested decision of the change, so the reason is worth stating
exactly: **add, remove and reorder produce records the server never rendered
markup for.** A new address has no `<li>` to enhance. Therefore the collections
have to be rendered from state regardless of what is done with the rest, and the
choice is not "enhance in place" versus "render from state" — it is "render from
state everywhere" versus "render from state for the collections and enhance in
place for the scalars". The second means two rendering mechanisms that describe
one record and can disagree about it, which is a worse problem than either
mechanism alone.

Two costs follow, and neither is hypothetical:

1. **Every label has to be handed over as JSON.** The component draws its own
   controls, so its text cannot come from `f:translate` in a template, and a
   literal in a TypeScript file is a string no XLIFF file knows about. The
   controller therefore translates a written-out list of keys and renders the
   result into `data-labels`. That list is a contract between two languages that
   no compiler checks — see [the label keys](#the-label-keys).
2. **The editing surface shows the raw `Y-m-d` birthday.** The detail plugin
   renders it through `f:format.date` without a `format` argument, so the format
   comes from the installation-wide `SYS/ddmmyy` setting. The component has no
   access to that setting, and inventing a second format in TypeScript would
   make the two views disagree about what is stored. It shows the wire value,
   which is also what the native date control edits. This is a visible
   inconsistency between the read view and the editing surface, and it is
   accepted rather than papered over.

The same reasoning decides what stays *outside* the element: the profile image,
because no endpoint manages it and nothing can make the rendered image disagree
with the server. The name heading is inside for the opposite reason — the
component edits the name, and a heading left outside would still show the value
the page was loaded with after the first save. `Profile/Card` is deliberately
not used here although it renders both; it bundles them, and this template needs
them on different sides of the element.

## Degradation

**The server-rendered profile is the no-JavaScript view.** It sits inside the
element, which is an unknown tag with children until it upgrades, and it is the
view a visitor keeps whenever the component does not enhance. For the *owner*
that view is the editing view without the editing — including the records they
have hidden, marked as hidden by the shared list partials, because a record the
owner hid is one the owner has to be able to find again.

The refusals are all-or-nothing, and there is no half-enhanced state:

| Condition                                                   | Where it is decided                            | Result                                                            |
|-------------------------------------------------------------|------------------------------------------------|-------------------------------------------------------------------|
| No JavaScript, no module resolution, no custom elements     | the browser                                    | The element never upgrades. Server markup stays.                  |
| `data-profile` missing, malformed, or not a usable document | `readJson()` / `parseProfileRecord()`          | `profile` stays `null`, `render()` answers a bare `<slot>`.       |
| `ajaxPageType` is `0` or unset                              | `ProfileEditController::endpointUris()`        | Empty attribute, `parseEndpoints()` refuses it, no enhancement.   |
| One endpoint URL missing or empty                           | `parseEndpoints()`                             | The whole map is refused, not that one action.                    |
| No `nonce` signing secret provider registered               | `ProfileEditController::issueRequestToken()`   | Empty token attribute, and an empty token means "do not enhance". |
| The stylesheet loaded but the module did not run            | `documentState.ts` sets `frontend-edit-loaded` | Every CSS rule is gated on that class, so nothing is styled.      |

Each of those is a deliberate refusal rather than a fallback. A surface with a
profile but no endpoints renders controls that cannot save; one with endpoints
but no token renders controls whose every save answers `403`. Both are worse
than the readable profile that is still in the light DOM. The one case that is
*not* all-or-nothing is the label map: a missing translation shows the key
itself, because a blank button looks like a rendering defect and is found much
later, while `action.save` on a button is self-explanatory.

`issueRequestToken()` returning an empty string is the sharpest of these. `f:form`
throws a `\LogicException` in the same situation; this plugin degrades instead,
because an exception page destroys the one thing progressive enhancement
promises.

## The server is the source of truth

There is no client-side model that is updated as the user types. The state is
exactly one thing — the `data` document of the most recent successful response,
or the one the server rendered into the markup — and it is replaced **wholesale,
never patched**.

A successful save therefore does not write anything back into a control: it
replaces the document and *ends* the session, and the field then renders the
persisted value. A value the server normalised becomes visible because the next
render reads it from the new document, not because anything compared it with
what was sent. A client that renders its own optimistic guess drifts away from
the database on the first rule that normalises a value, and the drift is
invisible until someone reloads.

The three answers are distinguished, and the distinction is not cosmetic:

| Answer                             | What happens to the session                                                             |
|------------------------------------|-----------------------------------------------------------------------------------------|
| `200` with a usable document       | State replaced, session ended, errors cleared.                                          |
| `422`                              | Session and drafts kept, messages placed at their fields, focus moved to the first one. |
| Anything else, including no answer | Session kept, one translated sentence of ours at the record.                            |

A failed save must neither look like a success nor discard what was typed, which
is why a `422` leaves everything standing. The endpoint's own `message` is
written for a developer and is deliberately **not** shown; the component picks
`error.request.<status>` when the site provides one — `403` after an expired
session and `409` while a workspace is active are the two a person can act on —
and `error.request` otherwise. A request that never produced a response is an
`error` result with status `0`, not a rejected promise: an unhandled rejection in
a UI event handler leaves a control disabled with no explanation.

## One factory, one document

`Http\ProfileDocumentFactory` builds the document the page embeds in
`data-profile` **and** the `data` object every endpoint answers with. Letter for
letter, from one producer.

That matters because the component replaces its state wholesale with the
document of the next successful response. Two producers of this shape would not
merely duplicate code — they would produce a surface that *changes on the first
save*: a field showing one thing after page load and another after a write the
server accepted, with nothing in either code path looking wrong. It is expensive
to debug and invisible in a review of either side alone.
`ProfileEditPluginTest::theEmbeddedDocumentIsIdenticalToTheReadEndpointDocument()`
fires a real request at the `read` endpoint and compares the decoded documents,
which is what keeps the single producer a fact rather than an intention.

The comparison is on the decoded documents and not on the two JSON strings,
because the encoding flags differ on purpose:

| Producer                            | Flags                                                       | Why                                                                                               |
|-------------------------------------|-------------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| `JsonEnvelope`                      | `THROW_ON_ERROR`, `UNESCAPED_SLASHES/UNICODE`               | Served as `application/json` and read with `Response.json()`. Nothing is embedded in HTML.        |
| `ProfileEditController::JSON_FLAGS` | the same, plus `HEX_TAG`, `HEX_AMP`, `HEX_APOS`, `HEX_QUOT` | Embedded in an HTML attribute, so no value may contribute a `<`, `>`, `&`, `'` or `"` of its own. |

What makes the attribute well formed is Fluid's `htmlspecialchars($value, ENT_QUOTES)`
on `{profileJson}` — the structural double quotes of the JSON document are not
string content and no encoding flag touches them. The `JSON_HEX_*` flags cover
the other half, so the document holds together even where the escaping is not in
play. A `f:format.raw` added later would end the attribute at the first quote,
which is why the template says so in its comment.

### The children are arguments, not something the factory fetches

`create()` takes the addresses and the e-mail addresses. It does not read them
off the profile and does not resolve them itself, because **the two callers must
not read them the same way and only the caller can know which is right**:

- Both current callers serve the **owner** and pass the `Edit\` repository
  collections, which include the hidden records.
- A caller serving a **visitor** — a read plugin, a future public endpoint —
  must not disclose them and would pass the display collections instead.

A factory deciding this itself would have to guess the audience from something
it cannot see: the session, the plugin it was called from, or a flag a caller
can pass wrongly. Visibility is an authorization decision, it belongs where
authorization is decided, and a signature that forces the caller to state it is
the cheapest way to keep it there.

The order of both collections is preserved as given. It is the stored sorting
order, which the surface renders and the reorder endpoint writes, and
re-sorting in the factory would silently disagree with both.

## The Fluid contract: four attributes

All four are rendered with plain `{variable}` interpolation, and all four are
required.

| Attribute        | Fluid variable  | Content                                                                                          |
|------------------|-----------------|--------------------------------------------------------------------------------------------------|
| `data-profile`   | `profileJson`   | The profile document, as JSON. The same shape every endpoint answers with.                       |
| `data-endpoints` | `endpointsJson` | One finished URL per action, as JSON. Six keys; `read` is deliberately absent.                   |
| `data-token`     | `requestToken`  | The request token as a hash-signed JWT, sent back in `X-TYPO3-RequestToken`.                     |
| `data-labels`    | `labelsJson`    | Every string the component renders, translated, as JSON. A key without a translation is omitted. |

The endpoint map is a map and not a base URL because the Extbase action travels
in the query string and is therefore part of the cHash, which cannot be computed
in a browser. `setTargetPageType()` is called rather than `setFormat('json')` —
both resolve to the same number through `view.formatToPageTypeMapping.json`, and
the page type is what the endpoint `PAGE` object is keyed on, so naming it
directly leaves nothing to a second configuration key. The target page is set
explicitly to the page the plugin sits on, because the `UriBuilder` fallback for
the `Ajax` plugin is a `tt_content` lookup that finds nothing — the endpoint
plugin has no content element.
→ [URLs are generated server-side](ajax-transport.md#urls-are-generated-server-side)

`read` is absent from the map on purpose, and its absence is a contract:
`parseEndpoints()` refuses a map missing one of the six and accepts one carrying
nothing else. The initial state is rendered into the markup and every write
answers with the whole aggregate, so a component that could read separately
would have a second way to learn the truth. Two ways is one too many.

## The label keys

The keys are built at runtime from a scope and a name, so nothing in the
TypeScript build can notice one that was never translated. The scope is part of
a field key because `type` exists on both child collections and means a
different thing in each.

| Shape                            | Built by            | Keys                                                                                                                                              |
|----------------------------------|---------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| `field.<scope>.<name>`           | `fieldLabelKey()`   | `field.profile.shortname`, `.firstname`, `.lastname`, `.birthday`, `.bio`; `field.address.type`, `.line1`, `.line2`; `field.email.type`, `.email` |
| `choice.<scope>.<field>.<value>` | `choiceLabelKey()`  | `choice.address.type.home`, `.work`, `.others`; `choice.email.type.private`, `.business`, `.others`                                               |
| `action.<name>`                  | `actionLabelKey()`  | `action.edit`, `.apply`, `.cancel`, `.editRecord`, `.save`, `.add`, `.remove`, `.moveUp`, `.moveDown`, `.hide`, `.show`                           |
| `section.<scope>`                | `sectionLabelKey()` | `section.address`, `section.email`                                                                                                                |
| `state.<name>`                   | `stateLabelKey()`   | `state.hidden`                                                                                                                                    |
| —                                | literal             | `error.request`, and optionally `error.request.403` and `error.request.409`                                                                       |

`ProfileEditController::LABEL_KEYS` spells the same list out, and the `id`
attributes in `locallang.xlf` are that list letter for letter. It is written out
rather than derived precisely because the contract crosses two languages: making
it one `grep` wide in both directions is the only check there is. This page is
the third spelling and is kept in step by review, like every partial argument
table.

`action.edit`/`action.apply` and `action.editRecord`/`action.save` are worded
differently on purpose — the two editing modes sit next to each other on one
surface, and a user who cannot tell them apart saves one field believing they
saved five.

## The testable-module split

The split runs along one line: **`component/` may touch the DOM, nothing else
may.**

| Directory                | Contents                                                                | Covered by                               |
|--------------------------|-------------------------------------------------------------------------|------------------------------------------|
| `component/`             | The two lit elements: shadow DOM, events, focus, the request sequencing | Nothing automated — see the gap below    |
| `model/`, `api/`         | Pure functions and one class: state, payloads, parsing, the client      | `-s unitJs`, `Build/Tests/TypeScript/`   |
| `Classes/`, `Resources/` | The server half: the three states, the four attributes, the assets      | `-s functional`, `ProfileEditPluginTest` |

Two mechanics make the second row possible and are worth knowing before adding a
module:

- **Everything outside `component/` contains only erasable syntax.**
  `ProfileEndpointClient` writes its constructor properties out longhand rather
  than using parameter properties for exactly this reason. Node strips the types
  and runs the result; a construct that has to be *emitted* rather than erased
  would need a bundler, which would be a second toolchain.
- **`Build/Tests/TypeScript/sourceResolve.mjs`** rewrites a relative `.js`
  specifier to `.ts` through `registerHooks()`. The sources import each other
  with `.js`, which is what the emitted ESM has to say; node does not rewrite it.
  That hook is the whole test setup — one `--import`, no jsdom, no bundler.

`npm test` is `node --test`, and `-s unitJs` runs it in the same node container
as the other frontend suites, so it is core version independent and needs no
`composerUpdate`.
→ [Frontend assets](frontend-assets.md#the-runtestssh-suites)

### What `unitJs` covers

- `editState` — that a draft survives a `422`, that cancelling reverts to the
  current state rather than to the seed, that a whole record session replaces a
  half-finished field session, that a session with nothing to report is dropped,
  and that no function writes into the map it was given.
- `profileRecord` — parsing a document defensively, rejecting one without a
  usable `uid`, normalising a mistyped scalar, dropping an unusable child, and
  that `movedChildOrder()` always answers a permutation and never a partial list.
- `payload` — that a field save carries exactly one field, that a child payload
  names its child, that a cleared field stays an explicit `null`.
- `response` — the three outcomes, and that a `422` naming nothing degrades to
  the generic failure rather than leaving the user with no explanation.
- `endpoints` — that an incomplete map is refused as a whole.
- `client` — the verb, the media type, `credentials: 'same-origin'`, the token
  header, that each action goes to its own URL, and that neither a failed request
  nor a non-JSON body throws.

### What only an acceptance harness can cover

Named as a gap rather than hidden. Everything in the list below needs a real
browser — a custom element upgrade, a shadow root, a focus ring, a native date
control:

- That the element **refuses to enhance** for each of the six refusal conditions,
  and that the light DOM stays visible when it does.
- That the shadow root does **not** slot the light DOM children once it upgrades.
- Focus movement: into a freshly opened control, and onto the first field a `422`
  named.
- The `<select>` value synchronisation in `updated()`, which exists because a
  `.value` binding is committed before the `<option>` children exist.
- Enter and Escape, including that Enter is not bound in a textarea.
- The real `fetch`, the real cHash-bearing URL and the real request token, end to
  end against a running TYPO3 instance.

Real-browser JavaScript tests are
[deliberately deferred](frontend-assets.md#the-toolchain-and-why-it-is-smaller-than-cores):
`@web/test-runner` needs a ~700 MB Chrome image and a hand-written import map.
The PHP functional test covers the server half of everything in that list that
has a server half, which is the four attributes and the assets — not the
behaviour of the component.

## Two duplications, recorded rather than hidden

Both are deliberate, both are bounded, and both are the kind of thing that is
cheap to write down now and expensive to rediscover.

### The six `choice.*` labels exist twice

The accepted values of the two `type` selects are labelled in two files:

| Value                 | Editing surface (`locallang.xlf`) | Read view and TCA (`locallang_db.xlf`)                          |
|-----------------------|-----------------------------------|-----------------------------------------------------------------|
| `address.type.home`   | `choice.address.type.home`        | `tx_modernextbasefrontendedit_domain_model_address.type.home`   |
| `address.type.work`   | `choice.address.type.work`        | `tx_modernextbasefrontendedit_domain_model_address.type.work`   |
| `address.type.others` | `choice.address.type.others`      | `tx_modernextbasefrontendedit_domain_model_address.type.others` |
| `email.type.private`  | `choice.email.type.private`       | `tx_modernextbasefrontendedit_domain_model_email.type.private`  |
| `email.type.business` | `choice.email.type.business`      | `tx_modernextbasefrontendedit_domain_model_email.type.business` |
| `email.type.others`   | `choice.email.type.others`        | `tx_modernextbasefrontendedit_domain_model_email.type.others`   |

The second column is what the read partials look up dynamically, so a type added
to the TCA later needs no template change. The first is what the component
needs, because its label contract is one flat map keyed by the structural keys
it builds at runtime.

**The consequence has to be stated: an installation that overrides only one of
the two files makes the read view and the editing surface disagree about the
same stored value.** The same address then reads *Home* on the detail page and
*Zuhause* — or the raw `home` — in the editor.

The alternative, resolving these six keys out of the database label file, keeps
one source and was rejected because the component's label contract would then be
spread across two files for six of its thirty-odd keys. Revisit it if a third
consumer of the choice labels appears.

### `fieldDefinitions.ts` duplicates the rule sets

`Build/Sources/TypeScript/model/fieldDefinitions.ts` repeats, per field, what a
browser needs in order to draw a control: the field order, the kind of input, the
accepted values of a select, the `maxlength`, and the value a new record starts
from. Those choices and those length limits are declared authoritatively in
`Validation\ProfileRuleSet`, `AddressRuleSet` and `EmailRuleSet`.

The duplication is bounded in exactly one direction, and that is what makes it
acceptable: **it cannot make an invalid value acceptable — it can only fail to
prevent one.** The endpoint refuses anything the rule sets do not declare, a
field name included, and answers `422` with a message that lands at the field. A
`maxlength` that drifted upwards costs a round trip and an error message; one
that drifted downwards costs a field a user cannot fill in. Neither is a
correctness problem on the server.

Keeping the two in step is therefore a review item and not a runtime concern.
The alternative — an endpoint that publishes the rule set as a schema — is a
public API this proof of concept does not need, and it would have to be
versioned the moment anything consumed it.

## See also

- [AJAX transport](ajax-transport.md) — the endpoints this plugin's component
  calls, the request token and the wire format.
- [Authorization](authorization.md) — where the real boundary is, and why an
  anonymous visitor here is a sentence and not a 403.
- [Plugins and the Fluid layer](plugins-and-fluid.md) — the registration this
  one follows, and the partials it reuses.
- [Persistence and sorting](persistence-and-sorting.md) — the display/edit
  repository split, and why a reorder carries the whole order.
- [DTOs and validation](dto-and-validation.md) — the rule sets
  `fieldDefinitions.ts` mirrors, and what a `422` contains.
- [Frontend assets](frontend-assets.md) — the import map, the build, and the six
  node based suites.
- [Quality gates](../development/quality-gates.md) — where `unitJs` sits among
  the gates.
