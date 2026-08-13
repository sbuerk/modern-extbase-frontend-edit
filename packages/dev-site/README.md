# `test_dev_site` — the development site package

A minimal, deliberately unremarkable site package. It exists so that the
editing surface of `modern_extbase_frontend_edit` is developed, photographed
and regression tested **inside a themed page**, rather than on the unstyled
white background a bare test instance renders.

It is a fixture. It is required under `require-dev` through a composer path
repository, it is never published, and `packages/` is `export-ignore`d so it
cannot reach the composer package of the extension.

| What                  | Value                 |
|-----------------------|-----------------------|
| Directory             | `packages/dev-site`   |
| Composer package name | `tests/dev-site`      |
| TYPO3 extension key   | `test_dev_site`       |
| Site set              | `tests/dev-site`      |

Those three names differ from each other on purpose and none of them can be
derived from the others — see [the note on naming](#the-three-names).

It carries an extension icon at `Resources/Public/Icons/Extension.svg` — the
same mark the extension itself uses, deliberately, so the fixture is not the one
entry in the extension list without a face. It never ships, for the same reason
nothing else here does.
→ [Brand assets](../../docs/development/brand-assets.md)

## What it provides

- A `PAGEVIEW` based page rendering with one layout, a header, a footer and one
  content area.
- A neutral, modern stylesheet with **light and dark schemes**, built from
  custom properties, that the editing surface can inherit from once the surface
  renders into the light DOM.
- Form control, button and card styling that a themed site would realistically
  have, so that "does the plugin look like it belongs here" is a question this
  repository can actually ask.

## What it is modelled on, and what it is not

It follows the *shape* of [`typo3/theme-camino`](https://github.com/TYPO3-CMS/theme_camino),
the default theme of the TYPO3 v14 series: a site set, plain CSS files composed
by `@import` into one `main.css`, a custom property layer, and Fluid page
templates with no build step of any kind.

It is **not** camino and cannot be. Camino requires `typo3/cms-core: 14.3.6`
exactly, has no release below v14.1, and declares `conflict: typo3/cms: *`, so
it cannot be installed next to a v13 dependency set at all. Two of the things it
does are v14 only and are therefore absent here:

- **Content areas** and `<f:render.contentArea>` arrived in **v14.2**. Content is
  rendered here through a TypoScript `CONTENT` object and `<f:cObject>`, which
  behaves identically on both versions.
- **The `.fluid.html` file extension** arrived in **v14.0**. Templates here are
  plain `.html`.

Camino also has **no dark scheme** — its `colorScheme` setting selects between
four colour palettes, not between light and dark. The dark scheme here is this
package's own.

## The three names

`packages/dev-site` is where it lives, `tests/dev-site` is what composer calls
it, and `test_dev_site` is the TYPO3 extension key. TYPO3 would normally derive
the extension key from the composer name (`tests/dev-site` → `dev_site`), which
is why `extra.typo3/cms.extension-key` states it explicitly. Anything that
references the extension — a `EXT:` path, a site set dependency — uses
`test_dev_site`.

## Deliberately missing

No backend layouts beyond the default, no menus, no responsive images, no
language handling beyond the single language the test instance configures, and
no content element templates of its own beyond what `fluid_styled_content`
brings. Every one of those would be work that the proof of concept does not
read on.
