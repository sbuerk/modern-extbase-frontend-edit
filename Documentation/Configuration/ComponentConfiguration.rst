..  include:: /Includes.rst.txt

..  _configuration-component:

=========================
Icons and CSS class names
=========================

Two things about the editing surface are decided by the installation rather than
by the extension: **which glyph each action draws**, and **which CSS classes each
kind of element carries**. Both are configured in one place.

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit'] = [
        'icons' => [
            'edit' => 'actions-open',
        ],
        'classes' => [
            'button' => 'button',
            'buttonPrimary' => 'button--primary',
            'control' => 'form-control',
        ],
    ];

Nothing here is required. Configured with nothing at all, the surface draws the
icons the extension ships and carries only its own class names.

..  note::
    This is deliberately a low level seam. :php:`TYPO3_CONF_VARS` is global, so
    the configuration cannot differ per site, per page or per plugin instance.

Making the surface look like the site
=====================================

The most useful thing this does is let the surface pick up a theme's own button
and form styling instead of imitating it. The classes are **added** to the
extension's own, never replacing them:

..  code-block:: php

    'classes' => [
        'button' => 'button',
        'buttonPrimary' => 'button--primary',
        'buttonDanger' => 'button--danger',
        'buttonIconOnly' => 'button--icon',
        'control' => 'form-control',
        'label' => 'form-label',
        'errors' => 'form-errors',
        'filePicker' => 'file-picker',
    ],

The element types that may be configured:

..  list-table::
    :header-rows: 1

    *   -   Element type
        -   What it is
    *   -   :php:`record`
        -   One record: the profile, or one child of it.
    *   -   :php:`child`
        -   One entry of a child collection.
    *   -   :php:`field`
        -   One field row: label, value, action.
    *   -   :php:`label`
        -   The label of a field.
    *   -   :php:`value`
        -   The stored value, while it is not being edited.
    *   -   :php:`control`
        -   The input, textarea or select a field is edited with.
    *   -   :php:`button`
        -   Every button the surface draws.
    *   -   :php:`buttonPrimary`
        -   Additionally, the button that commits a pending change.
    *   -   :php:`buttonDanger`
        -   Additionally, a button that destroys something.
    *   -   :php:`buttonIconOnly`
        -   Additionally, a button drawn as a glyph with a hidden label.
    *   -   :php:`filePicker`
        -   The label that opens the image picker.
    *   -   :php:`errors`
        -   The list of validation messages.
    *   -   :php:`state`
        -   The badge on a hidden record.

An unknown element type is ignored rather than carried into the page, so a typo
cannot look as though it worked.

Replacing an icon
=================

Each action is drawn from an icon **identifier**, resolved through TYPO3's icon
registry on the server. There are two ways to change one.

Point the action at a different icon:

..  code-block:: php

    'icons' => [
        'edit' => 'actions-open',
    ],

Or keep the identifier and re-register it from an extension of your own, which
replaces the glyph everywhere it is used:

..  code-block:: php
    :caption: EXT:my_extension/Configuration/Icons.php

    return [
        'modern-extbase-frontend-edit-edit' => [
            'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
            'source' => 'EXT:my_extension/Resources/Public/Icons/pencil.svg',
        ],
    ];

Neither needs a JavaScript build.

The configurable actions are :php:`edit`, :php:`editRecord`, :php:`apply`,
:php:`cancel`, :php:`add`, :php:`remove`, :php:`chooseImage`, :php:`moveUp`,
:php:`moveDown`, :php:`moveToTop`, :php:`moveToBottom`, :php:`hide` and
:php:`show`.

..  note::
    An identifier that is not registered leaves the button without a glyph. It
    keeps its label and stays usable, so a mistyped identifier costs one icon
    rather than the surface.

An icon registered with the sprite provider — which is how TYPO3's own
:php:`actions-*` icons are registered — is drawn as a reference into a sprite
file. That works, but such an icon does not follow the colour of the button it
sits in, so the emphasised and destructive buttons will show it in the plain
text colour. Icons registered with
:php:`\\TYPO3\\CMS\\Core\\Imaging\\IconProvider\\SvgIconProvider` do not have
that limitation.

..  seealso::
    :ref:`configuration-styling` for the custom properties, and
    :ref:`configuration-csp` for why no icon is fetched from another origin.
