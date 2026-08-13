# The development site package

`packages/dev-site` is a site package that exists so the editing surface is
developed, photographed and regression tested **inside a themed page** instead
of on the bare white background a test instance renders by default.

It is a fixture, not a product. It is `require-dev` through a composer path
repository, `packages/` is `export-ignore`d, and nothing about it ships.

## Three names, none derived from the others

| What                  | Value               |
|-----------------------|---------------------|
| Directory             | `packages/dev-site` |
| Composer package name | `tests/dev-site`    |
| TYPO3 extension key   | `test_dev_site`     |
| Site set name         | `tests/dev-site`    |

TYPO3 would derive the extension key `dev_site` from the composer name, which is
not what is wanted, so `extra.typo3/cms.extension-key` states `test_dev_site`
explicitly. Every `EXT:` path uses the extension key; the site set dependency
and `linkTestExtensionsToInstance()` use the composer name. Getting the two the
wrong way round produces an instance that boots and a site that renders nothing.

## Why it is not `typo3/theme-camino`

The obvious move is to require camino, the default theme of the TYPO3 v14
series, and the registry says no:

- It requires `typo3/cms-core: 14.3.6` — an exact version, not a range.
- Its oldest release is v14.1.0. There is no v13 compatible release to pin.
- It declares `conflict: typo3/cms: *`.

So it cannot be installed beside a v13 dependency set at all. What is taken from
it is its **shape**, which is worth taking: a site set, plain CSS files composed
by `@import` into one `main.css`, a custom property layer, Fluid page templates,
and **no build step of any kind**. No sass, no bundler, no compiled artifact to
keep in step — which matters more in a repository that exists to be read than it
would in a product.

Two of the things camino does are v14 only and are therefore absent here:

| Feature                                 | Introduced | Used here                         |
|-----------------------------------------|------------|-----------------------------------|
| `PAGEVIEW` content object               | **v13.1**  | yes                               |
| Content areas, `<f:render.contentArea>` | v14.2      | no — `CONTENT` plus `<f:cObject>` |
| `.fluid.html` template extension        | v14.0      | no — plain `.html`                |

Camino also has **no dark scheme**. Its `colorScheme` setting picks between four
colour palettes, not between light and dark. The dark scheme here is this
package's own.

## The colour scheme has three states, not two

`prefers-color-scheme` alone cannot be photographed: a headless browser reports
`light`, so a dark screenshot would need a media feature emulated per shot. The
site setting `devSite.colorScheme` pins it instead, and the page renders it as
`<body data-color-scheme>`:

| Value   | Behaviour                                                  |
|---------|------------------------------------------------------------|
| `auto`  | Follows `prefers-color-scheme`. The default.               |
| `light` | Forces light whatever the visitor prefers.                 |
| `dark`  | Forces dark. The only way the dark scheme can be captured. |

The attribute is rendered as `auto` rather than omitted, because an absent
attribute cannot distinguish "follow the visitor" from "the body tag was not
rendered".

The dark palette is declared on `body[data-color-scheme]` rather than on
`:root`. Reaching `:root` from an attribute on `body` needs `:root:has(…)`, and
`:has()` requires Firefox 121 against the Firefox 108 floor the import map
mechanism sets for the editing surface. Nothing outside `body` reads a token, so
the only consequence is that the page canvas is painted on `body`.

→ [Core version aware code](../architecture/core-version-aware-code.md)

## What it does not do

No webfont — the extension's own Content Security Policy refuses a font from
another origin, and a theme that needed one would make the plugin's security
posture look like a defect. No `color-mix()`, for the floor reason above. No
backend layouts, no menus, no language handling beyond the single language the
test instance configures, and no content element templates beyond what
`fluid_styled_content` brings.

## What the theme does and does not reach

This is the part that is easy to get wrong, and it was measured rather than
assumed.

**Inherited properties cross a shadow boundary. Selectors do not.** When this
package was first wired in, every visual regression baseline failed — the field
rows had grown between one and four pixels. Nothing had reached into the shadow
root: `font-family`, `line-height` and `color` are inherited properties, the
component's own `--frontend-edit-font-family` is `inherit`, and the theme's type
metrics therefore changed the height of every row.

What did **not** change was anything the theme styles by selector. `.button` and
`.form-control` in this package cannot reach a control inside a shadow root, so
the surface kept its own appearance entirely.

That is the whole argument for
[moving the components into the light DOM](../frontend-edit/styling.md), and
until that lands this package themes the page around the surface rather than the
surface itself.

## `_plugin.css` is the only file here that knows the plugin exists

Every other stylesheet in this package is written as though the extension were
not installed, which is the point of the fixture: an integrator's design system
does not have a chapter about somebody's plugin. The wiring lives in one file,
loaded last, and it does one thing — declare each of the surface's design tokens
in terms of this theme's scale.

**It maps all of them.** A token it does not list is a value that exists twice
and will drift, so the omission is a gate failure rather than a matter of taste:
`Build/Scripts/runTests.sh -s checkDesignTokenWiring`. The three shapes a wired
token can take, and why colour is mapped by role rather than by value, are in
[Styling](../frontend-edit/styling.md#every-token-has-one-source-and-a-gate-says-so).

Wiring it also pulled a set of literals in this package up into
`_variables.css` — the control height, the vertical control padding, the disabled
opacity, the transition duration and curve, and the two weights that carry
meaning. They were typed identically in `_form.css` and `_button.css` and agreed
by having been typed the same, which is the same defect one level down.

Two of the tokens `_variables.css` now declares are used by **nothing but** the
editing surface: `--measure-form` and `--form-label-width`. They live here anyway
because proportion is the theme's decision. `--measure-form` is deliberately not
`--measure`: a form with a label column beside its values needs more room than a
column of prose, and folding the two together would have narrowed the surface by
2rem to make a mapping look tidier.

**One consequence is worth naming.** The surface now follows the scheme *this
theme* is in rather than the scheme the *browser* is in. Those are not the same
question here — see [the three states](#the-colour-scheme-has-three-states-not-two)
— and before colour was mapped, a page pinned to `dark` through
`devSite.colorScheme` drew a light editing surface on a dark page, because the
extension's own dark values sit behind `prefers-color-scheme` and nothing else.
The extension's `@media` block is still its fallback for a site that declares
nothing; in this instance it is inert, which is the correct outcome rather than a
dead rule.

## See also

- [Quality gates](quality-gates.md)
- [Acceptance tests](../testing/acceptance-tests.md) — the instance this package
  is installed into, and the screenshots taken against it.
- [Styling](../frontend-edit/styling.md) — the surface's own layer.
