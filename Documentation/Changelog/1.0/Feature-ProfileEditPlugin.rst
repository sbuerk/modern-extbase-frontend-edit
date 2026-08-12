..  include:: /Includes.rst.txt

..  _feature-profile-edit-plugin:

============================
Feature: Profile edit plugin
============================

Description
===========

The extension now ships the editing interface the
:ref:`editing endpoints <feature-profile-editing-endpoints>` were built for.
A third plugin, :guilabel:`Profiles: edit`, shows the logged-in website user
their own profile and lets them change it without leaving the page and without
a backend login.

What can be edited:

..  list-table::
    :header-rows: 1

    *   -   Part of the profile
        -   What the visitor can do

    *   -   The profile fields
        -   Change the short name, first name, last name, birthday and
            biography — one field at a time, or all of them together.

    *   -   Postal addresses
        -   Add, change, remove, reorder, and hide or publish a single address.

    *   -   E-mail addresses
        -   The same, for e-mail addresses.

Two ways of editing sit next to each other, and they are labelled differently on
purpose:

:guilabel:`Edit` and :guilabel:`Apply`
    One field. :guilabel:`Apply` sends **only that field**, and
    :guilabel:`Cancel` puts the field back to the value the server last
    confirmed — which after a successful save is that saved value, not the one
    the page was opened with. :code:`Enter` applies and :code:`Escape` cancels;
    in the biography :code:`Enter` inserts a line break instead, because taking
    it away would make a biography a single line.

:guilabel:`Edit all fields` and :guilabel:`Save all fields`
    Every field of one record at once, saved in a single request.

Every save is answered with the profile as it is **stored afterwards**, and that
answer is what the page then shows. A value the server trims, normalises or
completes therefore becomes visible immediately, and the surface cannot drift
away from what is in the database. A save that is rejected keeps what was typed
and shows the reason at the field it belongs to, so nothing has to be entered
again.

The plugin also shows the addresses and e-mail addresses the owner has
**hidden**, marked as hidden. They are invisible to visitors on the list and the
detail plugin, which is exactly why the owner needs a place to find them again
and publish them.

Installation and configuration
==============================

Place the content element :guilabel:`Profiles: edit` from the
:guilabel:`Plugins` group on the page that the :yaml:`editPageUid` setting
names — the page the list and the detail plugin already link to for profiles the
visitor owns.

The plugin takes no arguments and has no plugin settings of its own. It resolves
the profile from the login, so it shows every visitor their own profile and
nobody else's, and there is nothing to configure per placement. One content
element on one page serves the whole site.

Two settings that already exist have to be right for it. Both are available as
site settings of the site set :guilabel:`Profiles` and as TypoScript constants:

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   TypoScript constant
        -   Why the edit plugin needs it

    *   -   :yaml:`modernextbasefrontendedit.persistence.storagePid`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.persistence.storagePid`
        -   The pages the profile records are stored on. A profile outside them
            is not found and the plugin reports that there is none. An address
            or e-mail address created from the frontend is written next to its
            profile, never onto a page a request named.

    *   -   :yaml:`modernextbasefrontendedit.ajaxPageType`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.ajaxPageType`
        -   The page type the editing endpoints answer on. The plugin builds its
            request addresses from it, and offers no editing at all when it is
            :code:`0` — see :ref:`below <feature-profile-edit-plugin-no-js>`.

..  note::

    The page holding this plugin is rendered uncached, because its markup
    carries a security token that is valid for one browser only, together with
    the profile of the logged-in user. Expect the page performance of any other
    uncached plugin.

All texts are translatable in the usual way, by overriding
:file:`EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf`.

..  warning::

    The labels of the address and e-mail :guilabel:`type` selections exist in
    **two** files: :file:`locallang.xlf` for the editing surface, and
    :file:`locallang_db.xlf` for the backend form and the read plugins.
    Overriding only one of the two makes the same stored value read differently
    in the profile display and in the editing surface. Override both, or
    neither.

..  _feature-profile-edit-plugin-no-js:

Without JavaScript
==================

The plugin renders the profile as ordinary, readable HTML, and the editing
surface is layered on top of it in the browser. Where that does not happen, the
readable profile is what stays on the page — never a half-working form, and
never an error page.

That is the deliberate answer in every one of these cases:

*   JavaScript is switched off, or the browser is older than the module
    mechanism this extension uses.
*   The script did not load, for instance because a caching layer in front of
    TYPO3 served an outdated version of the page.
*   :yaml:`ajaxPageType` is :code:`0`, or that page type is answered by
    something else on the site.
*   The installation has no security token provider configured.

An editor can therefore leave the plugin on the page in any of those situations
and the page still shows the profile. What is missing is the editing, not the
content.

Visitors who are not logged in see a sentence asking them to log in, and
visitors who are logged in but have no profile record see a different sentence
saying so. Neither is an error page, because the page may be linked from
anywhere.

Known limitations
=================

Simultaneous edits overwrite each other
    Two people — or two browser tabs — editing the same profile overwrite each
    other's changes, and neither is told. The last save wins. There is no
    warning, no merge and no "this record was changed meanwhile" answer. Where
    that matters, treat a profile as edited by one person at a time.

The profile image is set and removed without a save step
    The image is part of this surface, and it behaves unlike every other field
    on it: picking a file uploads it straight away, so there is no
    :guilabel:`Apply` and nothing to cancel. What may be uploaded, where the
    files are stored and what happens to a replaced one is described in
    :ref:`feature-profile-image-upload`.

The birthday is edited in the technical date format
    The profile display formats the birthday according to the installation's
    date format setting. The editing surface shows and edits it as
    :code:`YYYY-MM-DD`, which is what the browser's own date control uses and
    what is actually stored. Reading the same date in two formats on two pages
    is a real inconsistency and is accepted for now — inventing a second format
    in the editing surface would risk displaying a date that is not the one
    stored.

A profile cannot be hidden or published from the frontend
    Unchanged from the endpoints release. Whether a profile is hidden is shown,
    and single addresses and e-mail addresses can be hidden and published, but
    the profile as a whole cannot. Use the backend.

A failed save is not rolled back
    Unchanged from the endpoints release. A save that reports an error should be
    repeated rather than assumed to have changed nothing.

Only the default language, and never in a workspace
    Unchanged from the endpoints release. A save attempted while a workspace is
    active is refused, with a message saying so, rather than silently changing
    the published record.
