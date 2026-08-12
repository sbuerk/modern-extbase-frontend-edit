# Modern frontend editing

The design of the frontend editing feature: how an Extbase aggregate with
relations is made editable from the frontend by a JavaScript component talking
to AJAX endpoints, without giving up authorization, validation or the two
supported core versions.

> [!NOTE]
> These pages document the **design and the reasoning behind it**, and the code
> is landing against them one change at a time. The domain, the schema, the
> ownership resolver, the three plugins, the DTO/validation/mapping layer, the
> write path, the nine endpoints, the frontend asset toolchain, the lit component
> that drives them and the image upload all exist. Where a page describes code
> that does not exist yet, it says so.
>
> Writing the code disproved ten statements these pages made while they were
> design only. All ten are corrected **in place**, next to the reasoning they
> replace, rather than collected in an errata list nobody reads:
> the content object the endpoint `PAGE` calls and the need for
> `config.no_cache` → [AJAX transport](ajax-transport.md#caching); what the
> `true` default of `getPropertyFromAspect('workspace', 'isLive', true)` covers
> → [Persistence and sorting](persistence-and-sorting.md#correction-what-the-true-default-actually-covers);
> that a `.gitignore` entry is what keeps php-cs-fixer out of
> `Build/node_modules` — it never was, because `ignoreVCSIgnored()` was reading
> no `.gitignore` at all
> → [Frontend assets](frontend-assets.md#correction-gitignore-was-never-what-kept-php-cs-fixer-out);
> and that the edit plugin would reuse `Profile/Card` — it cannot, because the
> card bundles the image and the name with the links that apply to a profile
> → [Plugins and the Fluid layer](plugins-and-fluid.md#the-fluid-layer).
>
> The image upload disproved five more, and the first of them was dangerous: the
> reference count that may delete a replaced file
> → [Image handling](image-handling.md#correction-one-remaining-reference-was-the-wrong-threshold);
> that `skipProperties()` is the only property mapping call a hand built upload
> needs → [the third trap](image-handling.md#allowproperties-is-the-third-trap-and-the-quietest);
> that `setMaxFiles(1)` expresses "one image"
> → [the fourth](image-handling.md#setmaxfiles1-makes-replacement-impossible);
> that a core validator message can be replaced with ours
> → [the validators](image-handling.md#one-core-message-cannot-be-overridden-on-v143);
> and that the image belongs outside the custom element
> → [The edit plugin](edit-plugin.md#the-enhanced-surface-is-client-rendered).

| Page                                                  | Contents                                                                                        |
|-------------------------------------------------------|-------------------------------------------------------------------------------------------------|
| [Domain and schema](domain-schema.md)                 | The three tables, their TCA, and why none of it needs a version conditional.                    |
| [Plugins and the Fluid layer](plugins-and-fluid.md)   | The three plugins, their registration and settings, and the partial API.                        |
| [The edit plugin](edit-plugin.md)                     | The two editing modes, the client-rendered surface, degradation, the one document factory.      |
| [Persistence and sorting](persistence-and-sorting.md) | What Extbase persistence does not do for us: sorting, orphans, hidden children, workspaces.     |
| [AJAX transport](ajax-transport.md)                   | Why a page type rather than eID or a middleware, the nine endpoints, the request token.         |
| [Authorization](authorization.md)                     | Ownership resolved from the session, the security checklist and where each defence lives.       |
| [DTOs and validation](dto-and-validation.md)          | Rules as data, full versus partial validation, hydration, the custom validators, the mappers.   |
| [Image handling](image-handling.md)                   | The two image endpoints, the upload rules, the read-side wrapper, replacement and cleanup.      |
| [Frontend assets](frontend-assets.md)                 | Import maps in the frontend, mapping `lit`, the TypeScript toolchain, the gates and the CI job. |
| [Styling](styling.md)                                 | The design tokens, why only the outer element declares them, and how a site overrides them.     |

## The short version

- The AJAX endpoint is a dedicated **page type running an Extbase plugin**.
  Neither eID nor a PSR-15 middleware can do the job: eID runs before frontend
  authentication, and a middleware before `prepare-tsfe-rendering` dies at the
  first repository call. → [AJAX transport](ajax-transport.md)
- The page calls **`EXTBASEPLUGIN` directly**, not the content object
  `configurePlugin()` generates — that one inherits `lib.contentElement` and
  would wrap every JSON body in a Fluid Styled Content frame `<div>`.
- **`config.no_cache = 1` is mandatory on that page**, not optional. A
  `USER_INT` plugin body runs *after* the page cache has been written, so
  disabling the cache from PHP is too late.
  → [AJAX transport](ajax-transport.md#caching)
- **Authorization is four statements, not an attribute.** `#[Authorize]` is
  v14-only; taking it would mean a duplicated controller and two versions
  running different code on the security path.
  → [Authorization](authorization.md#the-boundary-is-code-and-it-is-not-an-attribute)
- The **read endpoint requires no token and makes no login check**, so that an
  anonymous read and a non-owner read answer identically instead of revealing
  which case occurred. → [Authorization](authorization.md)
- A profile is **owned by a frontend user**. Endpoints resolve it from the
  session and never trust a uid from the client; child records are reached by
  filtering the already-owned set. → [Authorization](authorization.md)
- The **read plugins render an edit link only for profiles the current frontend
  user owns — and that is a display decision, not a boundary.** A link that is
  not drawn is still reachable by typing the URL.
  → [Plugins and the Fluid layer](plugins-and-fluid.md)
- **Validation rules are data, not attributes.** Three Extbase attributes have
  no spelling that is valid and deprecation-free on both core versions.
  → [Version neutral attributes](../architecture/version-neutral-attributes.md)
- **The DTOs carry raw JSON strings, never converted values**, because full and
  partial validation share one rule set and must therefore see one type.
  Conversion is the mapper's job, and the wire format is pinned by a constant.
  → [DTOs and validation](dto-and-validation.md#why-every-property-is-a-plain-string)
- **A payload cannot reach `pid` or `uid`** — not because something checks for
  them, but because the only path into a model is a closed `switch` over the
  writable properties, and `_setProperty()` is never called.
  → [DTOs and validation](dto-and-validation.md#pid-and-uid-are-impossible-by-mechanism-not-by-check)
- Persistence is **Extbase, not DataHandler** — a deliberate trade that costs
  `sys_history` and DataHandler hooks, and puts sorting and orphan removal in
  our hands. It does **not** cost reference index maintenance: Extbase updates
  the index for every row it writes, which is what the file cleanup relies on.
  → [Image handling](image-handling.md)
  → [Persistence and sorting](persistence-and-sorting.md)
- The schema is language, workspace, soft-delete and hidden aware, but the edit
  plugin **refuses to write while a workspace is active**. That gap is named
  rather than hidden.
- Four further gaps are named rather than hidden. `persistAll()` is **not a
  transaction**, so a mid-flush failure leaves a partially written aggregate;
  **densification is not repaired**, so pre-existing gaps in `sorting` survive;
  and the profile's own **`hidden` flag is readable and not writable**.
  → [Persistence and sorting](persistence-and-sorting.md#what-the-write-path-does-not-do)
  The fourth is that the profile's **image carries no metadata editing** — no
  alternative text, no title, no cropping — and that a replaced file is only
  *unlinked* on a storage without a `_recycler_` folder.
  → [Image handling](image-handling.md#named-gaps)
- **The image is the one endpoint that is not JSON.** A file cannot travel in a
  JSON body without base64, so `uploadImage` is `multipart/form-data` — and the
  client must not set the `Content-Type` header itself, because the boundary is
  the browser's to add. Everything else about the request and the whole answer
  are unchanged. → [Image handling](image-handling.md#the-two-endpoints)
- **A replaced file is deleted only when nothing but our own reference row
  points at it.** Deleting a `sys_file` hard-deletes every `sys_file_reference`
  in every table, so a guard that is off by one costs somebody else's record.
  → [Image handling](image-handling.md#the-cleanup-and-the-safeguard-that-makes-it-safe)
- **The edit plugin has a controller of its own**, because the read controller
  never touches the `Edit\` repositories and the owner's editing view must show
  the children the owner hid. → [The edit plugin](edit-plugin.md)
- **The enhanced surface is client-rendered, not enhanced in place.** Add, remove
  and reorder produce records the server never rendered markup for, so the
  collections come from state regardless — and two rendering mechanisms that can
  disagree about one record is the worse problem.
  → [The edit plugin](edit-plugin.md#the-enhanced-surface-is-client-rendered)
- **Apply sends only that field; cancel reverts to the last server-known value**
  — not to the value at page load, because the two differ as soon as one save
  has succeeded. → [The two editing modes](edit-plugin.md#the-two-editing-modes)
- **Nothing is ever half enhanced.** A malformed attribute, an unconfigured
  endpoint page type or a missing nonce provider all mean "do not enhance", and
  the server-rendered profile stays readable.
  → [Degradation](edit-plugin.md#degradation)
- **One factory builds the embedded document and every endpoint response.** Two
  producers would drift, and a drifted document surfaces as a field silently
  reverting after a save the server accepted.
  → [One factory, one document](edit-plugin.md#one-factory-one-document)
- **Two duplications are recorded rather than hidden**: the six `choice.*` labels
  exist in both XLIFF files, and `fieldDefinitions.ts` repeats the choices and
  length limits of the PHP rule sets — where it can only fail to prevent an
  invalid value, never make one acceptable.
  → [Two duplications](edit-plugin.md#two-duplications-recorded-rather-than-hidden)
- **Asset loading needs no version split.** Import maps behave identically in
  the frontend on v13 and v14, and `'dependencies' => ['core']` makes `lit`
  resolvable from a frontend page — bundling a second copy would break custom
  element registration, which is a correctness problem and not a payload one.
  → [Frontend assets](frontend-assets.md)
- **The compiled assets are committed, and one gate is what makes that safe.**
  Neither composer nor TER runs a build step, so the artifacts have to ship —
  and an artifact that drifts from its source is invisible to every other check.
  → [Frontend assets](frontend-assets.md#artifacts-are-committed-and-that-makes-a-gate-mandatory)

## See also

- [Architecture](../architecture/Index.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [Testing](../testing/Index.md)
- [Documentation index](../Index.md)
