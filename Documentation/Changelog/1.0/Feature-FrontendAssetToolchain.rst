..  include:: /Includes.rst.txt

..  _feature-frontend-asset-toolchain:

=================================
Feature: Frontend asset toolchain
=================================

Description
===========

The extension now ships the JavaScript and CSS assets its frontend editing
plugin will use, together with the import map entry that makes them loadable
from a frontend page:

..  list-table::
    :header-rows: 1

    *   -   Ships
        -   As

    *   -   An ES module
        -   :file:`EXT:modern_extbase_frontend_edit/Resources/Public/JavaScript/frontend-edit.js`,
            addressable as :js:`@sbuerk/modern-extbase-frontend-edit/frontend-edit.js`

    *   -   A stylesheet
        -   :file:`EXT:modern_extbase_frontend_edit/Resources/Public/Css/frontend-edit.css`

    *   -   The import map entry
        -   :file:`Configuration/JavaScriptModules.php`, mapping the prefix
            :js:`@sbuerk/modern-extbase-frontend-edit/` to the public JavaScript
            directory

Import maps are not a backend feature. TYPO3 emits them for frontend pages as
well, which is why an extension asset can be an ES module with bare import
specifiers rather than a bundled script tag. The module declares
:php:`'dependencies' => ['core']`, so everything :file:`EXT:core` publishes —
notably :js:`lit` — resolves inside it without a copy being shipped here.

..  note::

    The module currently only marks the document, by setting the CSS class
    :html:`frontend-edit-loaded` on :html:`<html>`. The editing user interface
    that uses it is part of a later release, and no plugin template loads the
    assets yet. What this release delivers is the asset pipeline and its
    addressing, not a visible change on the website.

No build step is required
=========================

The compiled files below :file:`Resources/Public/` are **part of the package**.
Installing the extension needs nothing beyond the usual:

..  code-block:: bash

    composer require sbuerk/modern-extbase-frontend-edit

Neither Composer nor an installation from the TYPO3 Extension Repository runs a
JavaScript build, so shipping the compiled result is the only way the assets can
be present. Node, npm and a network connection are needed to *develop* the
extension, never to *use* it. The sources they are compiled from live in
:file:`Build/`, which is excluded from the distributed package.

..  important::

    Do not edit the files below :file:`Resources/Public/` in an installation.
    They are generated, they carry a header saying so, and the next update of
    the extension overwrites them without warning. Everything below describes
    how to change the result without touching them.

Overriding the stylesheet
=========================

The stylesheet is deliberately minimal and is driven by two custom properties,
so the common case needs no override at all — redefine them anywhere in the site
CSS that loads after it:

..  code-block:: css

    :root {
        --frontend-edit-outline-color: #b30000;
        --frontend-edit-outline-width: 2px;
    }

Every rule in the file is scoped to the :html:`frontend-edit-loaded` class the
module sets on :html:`<html>`. That is what keeps a page unstyled when the
module fails to load instead of showing editing affordances that respond to
nothing — a rule that has to apply unconditionally must not be written under
that class.

To replace the stylesheet entirely, override the Fluid template that loads it
through :typoscript:`plugin.tx_modernextbasefrontendedit.view.templateRootPaths`
and point :html:`<f:asset.css>` at a file of your own.

Extending the JavaScript
========================

The import map maps a **prefix**, not a single file, so every file below
:file:`Resources/Public/JavaScript/` is addressable by its own specifier. An
extension of your own can therefore import from this one:

..  code-block:: php
    :caption: EXT:my_extension/Configuration/JavaScriptModules.php

    return [
        'dependencies' => [
            'core',
            'modern_extbase_frontend_edit',
        ],
        'imports' => [
            '@vendor/my-extension/' => 'EXT:my_extension/Resources/Public/JavaScript/',
        ],
    ];

..  code-block:: javascript
    :caption: EXT:my_extension/Resources/Public/JavaScript/custom.js

    import { assetsLoadedClass } from '@sbuerk/modern-extbase-frontend-edit/frontend-edit.js';

Loading a different module in place of the shipped one is a template decision:
override the Fluid template and change the :html:`identifier` of
:html:`<f:asset.module>`. Nothing forces the shipped module to be loaded at all.

..  important::

    The computed import map is cached in the :php:`assets` cache, and for a
    prefix mapping the file list is enumerated once. After adding a JavaScript
    file to an extension in a production installation, flush the caches — in
    :php:`Development` context the map is recomputed on every request.

Known limitations
=================

No user interface yet
    The shipped module is scaffolding. Until the editing component lands, adding
    the assets to a page changes nothing a visitor can see.

The module is not loaded by any template
    The import map entry and the compiled files exist and are addressable, but
    the plugin templates of this release do not reference them.

Modern browsers only
    Import maps are used without a polyfill, following TYPO3 core. The floor is
    Chrome 89, Firefox 108 and Safari 16.4; older browsers cannot resolve the
    module at all. This is inherited from TYPO3, not chosen by this extension.
