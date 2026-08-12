..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

What does it do?
================

The extension manages a **profile** record and its two child collections —
postal addresses and e-mail addresses — from the website itself, without a
backend login and without a full page form.

A visitor who owns a profile sees it rendered as an ordinary page. Once the
editing component has loaded, each field gains an :guilabel:`Edit` affordance
that turns it into a control, applies it on its own, and shows the value the
server stored. Child records can be added, removed, reordered and hidden, and a
profile image can be uploaded and replaced.

Three plugins are placed as content elements:

..  list-table::
    :header-rows: 1

    *   -   Plugin
        -   Renders
    *   -   :guilabel:`Profiles: list`
        -   Every profile of the configured storage page, each linking to the
            detail page.
    *   -   :guilabel:`Profiles: detail`
        -   One profile, with its addresses and e-mail addresses.
    *   -   :guilabel:`Profiles: edit`
        -   The profile of the logged-in website user, editable.

A fourth plugin answers the JSON requests the editing surface sends. It is
deliberately not placeable as a content element.

What it demonstrates
====================

The extension exists to show that this can be built with the framework as it
is, and to record what that costs. The decisions worth knowing about before
reading the code:

*   Records are written with the Extbase persistence manager rather than with
    :php:`DataHandler`. That is what makes the write path short, and it is why
    no :sql:`sys_history` entry is written and no :php:`DataHandler` hook runs.
    The reference index is not affected — Extbase maintains it for every row it
    writes.
*   The record a request may write is resolved from the session, never from an
    identifier the client sent.
*   Editing is refused while a workspace is active, and the surface says so
    before anything is typed.
*   The interface degrades: the website renders the whole record, the component
    replaces it, and a visitor without working JavaScript keeps the rendered
    version.

Every one of these has a limit attached to it. They are collected in
:ref:`known-limitations`.

..  _introduction-core-version-aware:

Core version aware implementations
==================================

Code that has to differ between the supported TYPO3 versions lives below
:file:`Core13/` and :file:`Core14/` in the repository root. Shared code —
interfaces, abstract base classes and everything working on both core
versions — lives in :file:`Classes/`.

Only the directory matching the running TYPO3 version is registered in the
dependency injection container, so a service asking for an interface always
receives the implementation matching the current core version.

Compatibility
=============

..  list-table::
    :header-rows: 1

    *   -   Branch
        -   Extension
        -   TYPO3
        -   PHP
    *   -   main
        -   1.x
        -   v13 / v14
        -   8.2 - 8.5

Contributing
============

Contributions are welcome. The development setup, the quality gates and the
commit message rules are described in the :file:`CONTRIBUTING.md` file of the
`source repository <https://github.com/sbuerk/modern-extbase-frontend-edit>`__.
