# Brand assets

Where the extension icon and the maintainer's logo live, and which surfaces show
them.

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

## Where each variant is shown

| File                                           | Consumer                    |
|------------------------------------------------|-----------------------------|
| `Resources/Public/Icons/Extension.svg`         | TYPO3 — extension list, TER |
| `Resources/Public/Icons/Logo/lockup-light.svg` | the README, light scheme    |
| `Resources/Public/Icons/Logo/lockup-dark.svg`  | the README, dark scheme     |

**The rendered manual shows no logo at all.** It did: a 96 pixel mark between
the start page's heading and its metadata field list, added together with the
README lockups. It was removed again because it did not look good there — a
judgement about the rendered page, made by the maintainer looking at it, and not
a constraint anything else follows from. The manual identifies the extension by
its title and its extension key.

That removal also took a duplicate with it. The mark had to exist twice —
once under `Resources/` for TYPO3 and once under `Documentation/` for the
renderer — because the two roots cannot reach across: the documentation renderer
copies `Documentation/` and nothing else, and TYPO3 resolves the extension icon
only at the fixed path above. Two files of the same 633 bytes with a note saying
to change both. There is now one.

**The README keeps its logo**, and it is a lockup rather than a mark, because
GitHub renders Markdown with real HTML and can therefore serve a different
source per colour scheme:

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

## Why no logo lives below `Documentation/`

`Tests/Acceptance/Support/screenshotWiring.ts` fails the
`checkDocumentationScreenshots` suite for **any** file below
`Documentation/files/images/` that no `figure::` or `image::` directive embeds.
It is what decided the layout here: the README lockups are embedded by no
chapter, so putting them there would have made a documentation gate fail over an
asset the documentation does not use.

That gate is also why dropping the mark from the start page had to take
`Documentation/files/images/logo/mark.svg` with it, and not only the directive.
An image left behind with nothing embedding it is exactly what the check
reports — which is the check working, and the reason the tree has no orphaned
artwork in it.

All logo variants live under `Resources/Public/Icons/Logo/` instead, which ships
with the package and needs no directive.

## See also

- [Quality gates](quality-gates.md) — `checkBom` covers these files; they are
  UTF-8 without BOM, LF, with a final newline, like everything else.
- [Component configuration](../frontend-edit/component-configuration.md) — the
  action icons, which are a different mechanism entirely.
