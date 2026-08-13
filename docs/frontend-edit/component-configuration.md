# Configuring the surface

Two things about the editing surface are decided by the installation rather than
by this extension: **which glyph each action draws**, and **which CSS classes
each kind of element carries**. Both used to be compiled into the JavaScript,
which made them unreachable for anybody who did not own this repository —
changing an icon meant editing TypeScript and rebuilding the assets of somebody
else's extension.

```php
// typo3conf/system/settings.php, or an AdditionalConfiguration.php
$GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit'] = [
    'icons' => [
        // action name => icon identifier
        'edit' => 'actions-open',
    ],
    'classes' => [
        // element type => additional CSS classes
        'button' => 'button',
        'buttonPrimary' => 'button--primary',
        'control' => 'form-control',
    ],
];
```

## The path a value takes

```
$GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit']
    │
    ├─ ComponentConfigurationFactory        merges over the defaults,
    │      │                                drops unknown keys,
    │      │                                resolves identifiers → markup
    │      ▼
    ├─ ComponentConfiguration (DTO)         JsonSerializable, #[Exclude]
    │      ▼
    ├─ ProfileEditController                json_encode into the template
    │      ▼
    ├─ data-config="…"                      on the custom element
    │      ▼
    └─ componentConfiguration.ts            parse, icon(), classesFor()
```

Everything below the factory consumes the DTO, so when this configuration grows
up — site settings, per-plugin configuration — the factory is the only class
that has to learn where to read from.

## Why the icons arrive as markup, not as identifiers

An identifier is meaningless in a browser: resolving one needs `IconRegistry`,
which is PHP. The only client-side alternative would be a request per icon to an
endpoint that does not exist and should not. So the server resolves it and the
**markup** travels; the indirection still exists, one layer earlier.

## Why the extension registers its own icons

Core's action icons — `actions-edit`, `actions-move-up` — are registered from
`EXT:core`, so they are available without EXT:backend and were the obvious first
choice. They are registered with `SvgSpriteIconProvider`, and that rules them
out: both its markup *and* its inline markup are

```html
<svg class="icon-color"><use xlink:href="…/actions.svg#actions-edit" /></svg>
```

an external reference into a sprite file. In a frontend that costs a request per
page, drags a backend class name into a site's markup, and — the deciding part —
does not inherit `currentColor` across the reference, so an icon could not follow
the colour of the button it sits in. The emphasised and destructive variants
depend on exactly that.

This extension's own icons use `SvgIconProvider` with a `source`, whose inline
markup is the sanitised file itself: one request fewer, no foreign class names,
and `currentColor` survives.

**A project replaces an icon in either of two places.** Re-register the
identifier from its own `Configuration/Icons.php` — identifiers are global — or
point the action at a different identifier in `TYPO3_CONF_VARS`. Neither touches
a line of JavaScript.

## What the sanitiser does to an icon, and one thing it removes

Inline markup goes through `SvgDocumentFactory::fromStringAndSanitize()`. That
is a feature — it is core's own SVG sanitiser — and it has one consequence worth
knowing, because it was found by a test rather than by reading:

> **`focusable="false"` cannot survive.** The attribute is not in the sanitiser's
> allow-list, so it is stripped however the file is written, and no icon
> resolved through `IconRegistry` can carry it.

It costs nothing here. `focusable` exists for Internet Explorer and pre-Chromium
Edge, both far below the browser floor the import map mechanism sets, and no
browser in that range makes an `<svg>` focusable by default. The accessibility
tree is covered by the `aria-hidden` on the wrapping `<span>`, which the
component draws and no sanitiser sees.

→ `Tests/Functional/Configuration/IconRegistrationTest.php`

## The classes are additive, and cannot remove anything

The surface always carries its own `frontend-edit-*` class first; the configured
value is appended. That is deliberate: those class names are what the
stylesheet and the acceptance suite address, so letting a settings file remove
one would let an installation break the surface and its own tests from
configuration.

| Element type     | Always carries                |
|------------------|-------------------------------|
| `record`         | `frontend-edit-record`        |
| `child`          | `frontend-edit-child`         |
| `field`          | `frontend-edit-field`         |
| `label`          | `frontend-edit-field-label`   |
| `value`          | `frontend-edit-field-value`   |
| `control`        | `frontend-edit-field-control` |
| `button`         | *(the element's own styling)* |
| `buttonPrimary`  | `data-variant="primary"`      |
| `buttonDanger`   | `data-variant="danger"`       |
| `buttonIconOnly` | `data-icon-only`              |
| `filePicker`     | `frontend-edit-file-picker`   |
| `errors`         | `frontend-edit-field-errors`  |
| `state`          | `frontend-edit-state`         |

Emphasis stays in `data-variant` rather than moving into the configured class,
for the reason it went there in the first place: an appearance concern must not
share an attribute with a selector the acceptance suite depends on.

## An unknown key is dropped, a missing one falls back

Both maps merge **over** the defaults, so renaming one icon keeps the other
twelve. A key naming no known action or element type is discarded rather than
carried into the document, so a typo cannot look like it worked. A mistyped
*identifier* resolves to an empty string — one button loses its glyph and keeps
its label, rather than the surface failing.

## Why `TYPO3_CONF_VARS` and not site settings

Because it is available in every context this extension renders in, including a
frontend sub-request, and it costs no schema. It is honestly the **low level**
seam and not a finished configuration story: `TYPO3_CONF_VARS` is global, so it
cannot differ per site, per page or per plugin instance, which a real
implementation would want.

## Serving it from an endpoint instead

The DTO is `JsonSerializable` and `json_encode()` of it is the complete
configuration, so an AJAX route returning it needs a controller action and no new
data structure. The attribute is preferred today for the reason every other
payload here travels in the document: a surface that renders before its
configuration arrives would draw one frame of unstyled, glyphless buttons.

## See also

- [Styling](styling.md) — the stylesheet the classes compose with.
- [Development site package](../development/dev-site-package.md) — the theme the
  acceptance instance configures these classes against.
