..  include:: /Includes.rst.txt

..  _editors-plugins:

=======
Plugins
=======

The extension adds three content elements. They are created like any other
content element, from the :guilabel:`Plugins` group of the "new content
element" wizard:

..  list-table::
    :header-rows: 1

    *   -   Content element
        -   Renders

    *   -   :guilabel:`Profiles: list`
        -   Lists the profiles of the configured storage page. Each entry links
            to the detail page, and to the edit page for profiles the logged-in
            website user owns.

    *   -   :guilabel:`Profiles: detail`
        -   Renders one profile with its addresses, e-mail addresses and image.
            The profile is selected by the link the list plugin renders.

    *   -   :guilabel:`Profiles: edit`
        -   Renders the profile of the logged-in website user as an editable
            surface. It takes no arguments: the record is resolved from the
            session, so the plugin shows every visitor their own profile and
            nobody else's.

None of the three has plugin settings of its own. There is no plugin tab and no
profile to pick per placement — everything is configured once for the whole
site, either as settings of the site set :guilabel:`Profiles` or as TypoScript
constants below :typoscript:`plugin.tx_modernextbasefrontendedit`. The backend
form of each element therefore only offers the general content element fields,
the header among them.

..  _editors-plugins-list:

Profiles: list
==============

Renders the heading :guilabel:`Profiles` and one entry per profile found on the
storage page. An entry consists of the profile image, the name, and the links
that apply to it:

*   :guilabel:`View profile` — rendered only when a detail page is configured.
*   :guilabel:`Edit profile` — rendered only when an edit page is configured
    **and** the profile belongs to the website user who is currently logged in.

With no profile to show, the plugin renders the sentence
:guilabel:`No profiles available.` rather than nothing at all, so a
misconfiguration is visible on the page.

The name shown is the first and last name; a profile that carries neither falls
back to its short name.

What it needs to be useful:

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   Why

    *   -   :guilabel:`Storage page`
        -   Required. The pages the profile records are stored on. There is no
            value meaning "every page" — without it the plugin lists nothing.

    *   -   :guilabel:`Detail page`
        -   The page holding :guilabel:`Profiles: detail`. Without it the
            entries carry no :guilabel:`View profile` link, and the list is a
            list of names.

    *   -   :guilabel:`Edit page`
        -   The page holding :guilabel:`Profiles: edit`. Without it no
            :guilabel:`Edit profile` link is rendered, for anybody.

..  _editors-plugins-detail:

Profiles: detail
================

Renders one profile: the image and the name, then the :guilabel:`Birthday` and
the :guilabel:`Biography`, then the :guilabel:`Addresses` and the
:guilabel:`E-mail addresses`. Each of those blocks is left out entirely when it
has nothing to show, and the e-mail addresses are rendered as links that honour
the installation's e-mail spam protection setting. An :guilabel:`Edit profile`
link is added when an edit page is configured and the visitor owns the profile.

The birthday is formatted according to the date format configured for the
installation.

Which profile is shown is decided by the link the list plugin renders, not by
the placement. The plugin is therefore placed **once**, on a page the list
links to, and serves every profile.

A link that names a profile which does not exist, or which is not on the
storage page, is answered with the site's own "page not found" response rather
than with an error page.

Hidden addresses and e-mail addresses are not rendered here. They are visible
to their owner only, in the edit plugin.

What it needs to be useful:

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   Why

    *   -   :guilabel:`Storage page`
        -   Required, and it has to be the same one the list plugin uses —
            otherwise the links the list renders point at profiles this plugin
            does not find.

    *   -   :guilabel:`Edit page`
        -   Optional. Without it no :guilabel:`Edit profile` link is rendered.

..  _editors-plugins-edit:

Profiles: edit
==============

Renders the heading :guilabel:`Your profile` and, below it, the profile of the
website user who is logged in — as an editable surface once the editing
component has loaded, and as a readable profile until then and wherever that
does not happen. What the visitor sees in each of the four possible situations
is described in :ref:`editors-editing-in-the-frontend`.

The plugin takes no arguments. It resolves the record from the login, so it
shows every visitor their own profile and nobody else's, and there is nothing
to choose per placement. Place it **once**, on the page the :guilabel:`Edit
page` setting names — the page the other two plugins link to.

What it needs to be useful:

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   Why

    *   -   :guilabel:`Storage page`
        -   Required. A profile stored outside these pages is not found, and
            the plugin then reports that the visitor has no profile.

    *   -   :guilabel:`Edit page`
        -   Not read by this plugin, but it is what makes the other two link to
            the page it sits on. Point it at that page.

    *   -   :guilabel:`Endpoint page type`
        -   The page type the editing requests are answered on. It has a
            working default and only has to be changed when another extension
            on the site already uses that number. Set to :code:`0`, the plugin
            renders the readable profile and offers no editing.

..  note::

    The page this plugin sits on is rendered uncached, because its markup
    carries the profile of the logged-in user together with a security token
    that is valid for one browser only.

..  _editors-plugins-endpoints:

The fourth plugin
=================

A fourth plugin answers the requests the editing surface sends. It is
deliberately **not** registered as a content element and does not appear in the
wizard: it is an endpoint, not something an editor places on a page.

It needs no page of its own either. The requests are answered on whichever page
the edit plugin sits on, under the page type the :guilabel:`Endpoint page type`
setting names.
