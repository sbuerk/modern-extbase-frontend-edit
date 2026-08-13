<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * The icon set of the editing surface, registered with TYPO3's `IconRegistry`.
 *
 * ## Why the surface has its own icons rather than using core's
 *
 * Core's own action icons - `actions-edit`, `actions-move-up` and the rest - are
 * registered by `IconRegistry::registerBackendIcons()` from
 * `EXT:core/Resources/Public/Icons/T3Icons/icons.json`, so they are available
 * without EXT:backend and using them was the obvious first choice. They are
 * registered with `SvgSpriteIconProvider`, and that is what rules them out here:
 * both its markup and its *inline* markup are
 *
 *     <svg class="icon-color"><use xlink:href="…/actions.svg#actions-edit" /></svg>
 *
 * an external reference into a sprite file. In a frontend that costs a request
 * per page, drags a backend CSS class (`icon-color`) into a site's markup, and -
 * the part that actually decides it - does not inherit `currentColor` across the
 * reference, so an icon could not follow the colour of the button it sits in.
 * The emphasised and destructive button variants depend on exactly that.
 *
 * These are registered with `SvgIconProvider` and a `source`, whose inline
 * markup is the sanitised file itself. One request less, no foreign class names,
 * and `stroke="currentColor"` survives - which
 * `Tests/Functional/Configuration/IconRegistrationTest.php` asserts rather than
 * assumes, because the sanitiser is entitled to drop attributes it does not like.
 *
 * ## Overriding one
 *
 * An identifier is global, so a project replaces an icon by registering the same
 * identifier from its own `Configuration/Icons.php` - no change to this
 * extension, and no rebuild of any JavaScript. Which identifier each *action*
 * uses is a separate question and is configuration:
 *
 *     $GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit']['icons']['edit']
 *         = 'actions-open';
 *
 * That is the seam that matters, and it is why the identifiers below are named
 * after this extension rather than after the actions they happen to serve today.
 */
return [
    'modern-extbase-frontend-edit-edit' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/edit.svg',
    ],
    'modern-extbase-frontend-edit-edit-record' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/edit-record.svg',
    ],
    'modern-extbase-frontend-edit-apply' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/apply.svg',
    ],
    'modern-extbase-frontend-edit-cancel' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/cancel.svg',
    ],
    'modern-extbase-frontend-edit-add' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/add.svg',
    ],
    'modern-extbase-frontend-edit-remove' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/remove.svg',
    ],
    'modern-extbase-frontend-edit-choose-image' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/choose-image.svg',
    ],
    'modern-extbase-frontend-edit-move-up' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/move-up.svg',
    ],
    'modern-extbase-frontend-edit-move-down' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/move-down.svg',
    ],
    /*
     * Registered before anything draws them. The "sort to top" and "sort to
     * bottom" actions are the next change; shipping the two glyphs with the rest
     * of the set keeps the icon layer one commit rather than two, and an
     * unreferenced icon costs nothing at runtime - `IconRegistry` is a lookup
     * table, not a loader.
     */
    'modern-extbase-frontend-edit-move-to-top' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/move-to-top.svg',
    ],
    'modern-extbase-frontend-edit-move-to-bottom' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/move-to-bottom.svg',
    ],
    'modern-extbase-frontend-edit-hide' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/hide.svg',
    ],
    'modern-extbase-frontend-edit-show' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:modern_extbase_frontend_edit/Resources/Public/Icons/Actions/show.svg',
    ],
];
