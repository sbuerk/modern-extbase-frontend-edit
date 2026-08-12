..  include:: /Includes.rst.txt

..  _feature-profile-list-and-detail-plugins:

========================================
Feature: Profile list and detail plugins
========================================

Description
===========

The extension now ships two frontend plugins that display the profile records:

..  list-table::
    :header-rows: 1

    *   -   Plugin
        -   Renders

    *   -   :guilabel:`Profiles: list`
        -   All profiles of the configured storage page. Every entry shows the
            image and the name, links to the detail page, and — for profiles
            the logged-in website user owns — to the edit page.

    *   -   :guilabel:`Profiles: detail`
        -   One profile with its image, birthday, biography, postal addresses
            and e-mail addresses. The profile is selected by the link the list
            plugin renders.

Both are regular content elements and are inserted from the
:guilabel:`Plugins` group of the content element wizard. They only read: no
record is created or changed from the frontend yet.

Only records a visitor may see are listed — hidden profiles, profiles outside
their start and stop time and profiles restricted to a website user group the
visitor is not in are left out.

Configuration
=============

Both plugins are configured through three settings. They are available as site
settings of the site set :guilabel:`Profiles` shipped by this extension — site
sets are available since TYPO3 v13.1 — and as TypoScript constants for
installations that configure their sites with :sql:`sys_template` records
instead. Adding the site set to a site is enough; the classic TypoScript is
included in any case and carries the same defaults.

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   TypoScript constant
        -   Meaning

    *   -   :yaml:`modernextbasefrontendedit.persistence.storagePid`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.persistence.storagePid`
        -   Comma separated list of page uids the profile records are stored
            on.

    *   -   :yaml:`modernextbasefrontendedit.showPageUid`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.showPageUid`
        -   The page holding the :guilabel:`Profiles: detail` plugin. The list
            plugin links its entries to it.

    *   -   :yaml:`modernextbasefrontendedit.editPageUid`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.editPageUid`
        -   The page holding the profile edit plugin. Both plugins link there
            for profiles the logged-in website user owns.

..  important::

    The storage page has to be set. There is no value meaning "all pages": an
    unconfigured plugin lists nothing, because profile records do not live on
    the root page.

A request for a profile that does not exist, or that the visitor may not see,
answers with the site's configured 404 page rather than with an error page.

Templates are overridden as usual, by adding
:typoscript:`plugin.tx_modernextbasefrontendedit.view.templateRootPaths`,
:typoscript:`partialRootPaths` and :typoscript:`layoutRootPaths` entries. The
templates are deliberately small and unstyled: each concept — the profile card,
the address list, the e-mail list, the image, the edit link — is a partial of
its own, so a single one can be replaced without copying the rest. The image
partial writes a plain ``<img>`` tag and applies no image processing, which
is the one most installations will want to replace.

The edit link
=============

The list and the detail plugin render a link to the edit page only for profiles
the logged-in website user owns, and nothing at all while no edit page is
configured.

..  warning::

    Showing or hiding that link is a **display** decision, not an access
    restriction. A link that is not rendered can still be opened by typing its
    address. Access to editing is enforced by the editing endpoints, which are
    described in :ref:`reference-endpoints`.

Known limitations
=================

Caching
    Both plugins are rendered uncached, because the edit link depends on the
    logged-in website user while the TYPO3 page cache distinguishes visitors by
    their user *groups* only. Two members of one group would otherwise share a
    cached rendering. Expect the same page performance as for any other
    uncached plugin.

The edit link needs a page to point at
    The link is only rendered once the edit page setting names a page, and only
    for a profile the logged-in website user owns. Leaving the setting empty
    suppresses the link entirely, which is the right state for a site that
    shows profiles but does not let anyone edit them. The plugin the link leads
    to is described in :ref:`feature-profile-edit-plugin`.

No "back to the list" link
    The detail plugin does not link back, because no setting names the page the
    list plugin sits on. Use the site navigation or a link in the page content.
