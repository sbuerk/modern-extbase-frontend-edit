# Modern frontend editing

The design of the frontend editing feature: how an Extbase aggregate with
relations is made editable from the frontend by a JavaScript component talking
to AJAX endpoints, without giving up authorization, validation or the two
supported core versions.

> [!NOTE]
> These pages document the **design and the reasoning behind it**, and the code
> is landing against them one change at a time. The domain, the schema, the
> ownership resolver, the two read plugins and the DTO, validation and mapping
> layer exist; nothing writes to the database yet and there is no HTTP endpoint.
> Where a page describes code that does not exist yet, it says so.

| Page                                                  | Contents                                                                                      |
|-------------------------------------------------------|-----------------------------------------------------------------------------------------------|
| [Domain and schema](domain-schema.md)                 | The three tables, their TCA, and why none of it needs a version conditional.                  |
| [Plugins and the Fluid layer](plugins-and-fluid.md)   | The two read plugins, their registration and settings, and the partial API.                   |
| [Persistence and sorting](persistence-and-sorting.md) | What Extbase persistence does not do for us: sorting, orphans, hidden children, workspaces.   |
| [AJAX transport](ajax-transport.md)                   | Why a page type rather than eID or a middleware, the JSON contract, the request token.        |
| [Authorization](authorization.md)                     | Ownership resolved from the session, the security checklist and where each defence belongs.   |
| [DTOs and validation](dto-and-validation.md)          | Rules as data, full versus partial validation, hydration, the custom validators, the mappers. |
| [Image handling](image-handling.md)                   | The modern upload API, why the custom model is a read-side wrapper, replacement and cleanup.  |
| [Frontend assets](frontend-assets.md)                 | Import maps in the frontend, mapping `lit`, the TypeScript toolchain and the gates it needs.  |

## The short version

- The AJAX endpoint is a dedicated **page type running an Extbase plugin**.
  Neither eID nor a PSR-15 middleware can do the job: eID runs before frontend
  authentication, and a middleware before `prepare-tsfe-rendering` dies at the
  first repository call. → [AJAX transport](ajax-transport.md)
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
  `sys_history`, DataHandler hooks and reference index maintenance, and puts
  sorting and orphan removal in our hands.
  → [Persistence and sorting](persistence-and-sorting.md)
- The schema is language, workspace, soft-delete and hidden aware, but the edit
  plugin **refuses to write while a workspace is active**. That gap is named
  rather than hidden.
- **Asset loading needs no version split.** Import maps behave identically in
  the frontend on v13 and v14. → [Frontend assets](frontend-assets.md)

## See also

- [Architecture](../architecture/Index.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [Testing](../testing/Index.md)
- [Documentation index](../Index.md)
