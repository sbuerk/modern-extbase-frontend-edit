..  include:: /Includes.rst.txt

..  _feature-component-configuration:

===================================================
Feature: Icons and CSS class names are configurable
===================================================

Description
===========

The editing surface no longer decides on its own which glyph an action draws or
which CSS classes its elements carry. Both are configured by the installation:

..  code-block:: php

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

The class names are **added** to the ones the surface always carries, which is
what lets it pick up a site's own button and form styling rather than imitating
it. The icons are resolved through TYPO3's icon registry on the server, so an
icon can also be replaced by re-registering its identifier from another
extension. Neither needs a JavaScript build.

Impact
======

Nothing has to be configured. Without any configuration the surface draws the
icons the extension ships and carries only its own class names, exactly as
before.

..  seealso::
    :ref:`configuration-component`
