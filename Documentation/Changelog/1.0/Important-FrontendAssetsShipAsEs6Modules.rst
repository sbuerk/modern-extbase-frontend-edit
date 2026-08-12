..  include:: /Includes.rst.txt

..  _important-frontend-assets-ship-as-es6-modules:

==============================================
Important: Frontend assets ship as ES6 modules
==============================================

Description
===========

The frontend JavaScript is no longer compiled into a single bundled file. Every
source module is emitted as its own ES6 module, imports between them survive
into the emitted code, and the browser resolves them through the TYPO3 import
map. Nothing is minified.

The assets also moved: JavaScript and CSS are now separated by application type,
so that backend assets have a place to go that is not the frontend one.

Paths
=====

..  list-table::
    :header-rows: 1

    -   -   Before
        -   Now
    -   -   :file:`Resources/Public/JavaScript/frontend-edit.js`
        -   :file:`Resources/Public/JavaScript/frontend/frontend-edit.js`
    -   -   :file:`Resources/Public/Css/frontend-edit.css`
        -   :file:`Resources/Public/Css/frontend/frontend-edit.css`
    -   -   :js:`@sbuerk/modern-extbase-frontend-edit/frontend-edit.js`
        -   :js:`@sbuerk/modern-extbase-frontend-edit/frontend/frontend-edit.js`

The import map prefix registered by this extension changed accordingly, from
:js:`@sbuerk/modern-extbase-frontend-edit/` to
:js:`@sbuerk/modern-extbase-frontend-edit/frontend/`.

Impact
======

An installation that only places the plugins needs to do nothing. Two things
change for an integrator who reaches into the assets:

*   A template or TypoScript that loads the stylesheet by path has to use the
    new one. There is no backwards compatible alias.
*   Every module is now addressable by its own specifier rather than only the
    entry point. That is the deliberate cost of an unbundled build, and it means
    the internals are importable — they are still internals, and they change
    without notice.

Why the modules import each other by specifier
==============================================

The emitted modules import each other as
:js:`@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js` rather than
relatively. TYPO3 enumerates every file below a trailing slash mapping into the
import map and gives each entry a cache busting key, and only a specifier that
is resolved *through* the map receives one: a relative specifier is resolved
against the URL of the importing module and drops the query string. A deploy
could then serve a fresh entry module alongside a dependency the browser still
has cached. The TYPO3 core ships no relative import in any of its own modules
for the same reason.
