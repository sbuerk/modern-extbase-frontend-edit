# Brand assets

Where the extension icon and the maintainer's logo live, and why one of them
exists twice.

## The extension icon is resolved by filename, not by configuration

`Resources/Public/Icons/Extension.svg` is not registered anywhere and must not
be. Core finds it by looking for one fixed path with one fixed basename:

```php
// .Build/vendor/typo3/cms-core/Classes/Package/Package.php:398
$resourcePath = 'Resources/Public/Icons/Extension.';
foreach (['svg', 'png', 'gif'] as $fileExtension) {
    if (file_exists($this->getPackagePath() . $resourcePath . $fileExtension)) {
        return $resourcePath . $fileExtension;
    }
}
```

Three things follow, and all three were checked against the installed core
rather than recalled:

- **SVG is tried first**, so a `.png` beside it would never be reached.
- `ext_emconf.php` has no icon key. TYPO3 has not had one for a long time.
- It **cannot collide** with the thirteen action icons in
  `Resources/Public/Icons/Actions/`. Those are found through the explicit array
  in `Configuration/Icons.php`; `IconRegistry` never scans the directory, and
  `Package::getPackageIcon()` never looks at the registry. `Extension.svg` also
  bypasses core's SVG sanitiser entirely, because that only runs for icons
  passed through `SvgIconProvider` — which is why it may carry the `width`,
  `height` and literal fills the action icons deliberately do not.
  → [Component configuration](../frontend-edit/component-configuration.md)

The API around it did move between the core versions — v13 deprecated
`ExtensionManagementUtility::getExtensionIcon()` (#102895) and v14 removed it
(#105377) — but the **file convention is identical on both**, and nothing here
calls either.

The development site package carries the same file at
`packages/dev-site/Resources/Public/Icons/Extension.svg`, so the fixture is not
the one extension in the list without a face. It never ships: `packages/` is
`export-ignore`d.

## The mark exists twice, on purpose

| File                                       | Consumer                    |
|--------------------------------------------|-----------------------------|
| `Resources/Public/Icons/Extension.svg`     | TYPO3 — extension list, TER |
| `Documentation/files/images/logo/mark.svg` | the rendered manual         |

They are the same 633 bytes. The duplicate is not tidiness lost but a
consequence of two roots: the documentation renderer copies `Documentation/`
and nothing else, so an `image::` cannot reach a file outside it, and TYPO3
resolves the icon only at the path above.

**If the mark changes, change both.** Nothing derives one from the other.

## Why the manual gets the mark and the README gets the lockup

The docs theme has a dark mode: `[data-bs-theme=dark]` sets the body background
to `#333333`. The lockup's ink is `#1a2028`, which on that background is not
legible, and **reStructuredText has no `<picture>` equivalent** — the theme
honours `:class:`, `:width:` and `:align:` on an image, but there is no hook for
a per scheme source and no place to add custom CSS.

The mark is drawn on its own dark tile and carries its own contrast, so it is
the one variant that is legible in both renderings. That is the whole reason the
manual shows a mark where the README shows a wordmark.

The README has the opposite constraint and the opposite answer. GitHub renders
Markdown with real HTML, so it gets a `<picture>` and both lockups:

```html
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="Resources/Public/Icons/Logo/lockup-dark.svg">
  <img src="Resources/Public/Icons/Logo/lockup-light.svg" alt="Stefan Buerk — hack0r" height="44">
</picture>
```

It sits **above** the `[!CAUTION]` block rather than replacing it as the first
thing read. That block is the most important sentence in the file — this is a
proof of concept and must not be copied into a product — and a logo tall enough
to push it below the fold would be a regression dressed as a polish.

## Where they may not live

`Tests/Acceptance/Support/screenshotWiring.ts` fails the
`checkDocumentationScreenshots` suite for **any** file below
`Documentation/files/images/` that no `figure::` or `image::` directive embeds.
That is what decided the layout above: the two README lockups are embedded by no
chapter, so putting them there would have made a documentation gate fail over an
asset the documentation does not use.

They live under `Resources/Public/Icons/Logo/` instead, which ships with the
package and needs no directive. The `logo/` subdirectory of the images tree is
outside the orphan check's scope — that one is derived from the screenshot
generator's own output directories — so `mark.svg` only has to be embedded, and
it is.

## See also

- [Quality gates](quality-gates.md) — `checkBom` covers these files; they are
  UTF-8 without BOM, LF, with a final newline, like everything else.
- [Component configuration](../frontend-edit/component-configuration.md) — the
  action icons, which are a different mechanism entirely.
