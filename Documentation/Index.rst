..  include:: /Includes.rst.txt

..  _start:

============================
Modern Extbase Frontend Edit
============================

..  image:: /files/images/logo/mark.svg
    :alt: Four rounded squares on a dark tile, the lower right one filled yellow — the mark of Stefan Buerk
    :width: 96px

:Extension key:
    modern_extbase_frontend_edit

:Package name:
    sbuerk/modern-extbase-frontend-edit

:Version:
    |release|

:Language:
    en

:Author:
    sbuerk

:License:
    This document is published under the
    `Open Content License <https://www.openhub.net/licenses/opl>`__.

:Rendered:
    |today|

----

Editing a profile record and its child collections directly on the page: every
field, every address, every e-mail address and the profile image, saved without
a page reload, from a web component that enhances markup the website already
rendered.

..  attention::

    **This is a proof of concept, not a product.** It exists to answer one
    question — can Extbase entities with relations be managed from the frontend
    with a modern, progressively enhanced interface — and to be read while
    answering it.

    Several decisions in it are deliberate trade-offs that would be wrong in a
    production extension, and they are documented as such rather than fixed.
    Read :ref:`known-limitations` before building on any of it.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What the extension does, and which TYPO3 and PHP versions it supports.

    ..  card:: :ref:`Installation <installation>`

        Install it, and the four things it needs before anything is visible.

    ..  card:: :ref:`Configuration <configuration>`

        The site set, every setting, the TypoScript, and overriding templates.

    ..  card:: :ref:`Editors <editors>`

        The three plugins, the profile record, and what editing looks like.

    ..  card:: :ref:`Reference <reference>`

        Tables, validation rules, the image upload bounds and the endpoints.

    ..  card:: :ref:`Known limitations <known-limitations>`

        What it deliberately does not do, and why.

    ..  card:: :ref:`Changelog <changelog>`

        Overview of the changes per released version.

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Editors/Index
    Reference/Index
    KnownLimitations/Index
    Changelog/Index
